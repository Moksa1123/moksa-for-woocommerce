<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Admin;

use Moksafowo\Modules\EcpayShipping\Module;
use Moksafowo\Modules\EcpayShipping\Operations\CreateOrder;
use Moksafowo\Modules\EcpayShipping\Operations\PrintLabel;
use Moksafowo\Modules\EcpayShipping\Operations\PrintProxy;
use Moksafowo\Modules\Shared\Admin\OrderInfoLayout;
use Moksafowo\Modules\Shipping\Tracking\TrackingLink;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox {

	private const NONCE_ACTION = 'moksafowo_ecpay_shipping_admin';
	private const CAPABILITY   = 'edit_shop_orders';


	private static array $method_id_cache = [];


	private static ?array $registry_titles_cache = null;

	private static function find_moksafowo_method_id( \WC_Order $order ): string {
		$oid = $order->get_id();
		if ( array_key_exists( $oid, self::$method_id_cache ) ) {
			return self::$method_id_cache[ $oid ];
		}
		$method_id = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid = (string) $m->get_method_id();
			if ( isset( Module::method_map()[ $mid ] ) ) {
				$method_id = $mid;
				break;
			}
		}
		self::$method_id_cache[ $oid ] = $method_id;
		return $method_id;
	}

	private static function registry_titles(): array {
		if ( null === self::$registry_titles_cache ) {
			$titles = [];
			foreach ( \Moksafowo\Modules\Shipping\Admin\BatchPrintRegistry::all() as $entry ) {
				foreach ( $entry['method_titles'] ?? [] as $mid_k => $title ) {
					$titles[ $mid_k ] = $title;
				}
			}
			self::$registry_titles_cache = $titles;
		}
		return self::$registry_titles_cache;
	}

	public static function init(): void {
		OrderInfoLayout::boot();
		add_filter( 'moksafowo_order_info_cards', [ __CLASS__, 'add_card' ], 20, 2 ); // priority 20 = 物流（金流10 物流20 發票30）
		add_action( 'wp_ajax_moksafowo_ecpay_shipping_create_order', [ __CLASS__, 'ajax_create_order' ] );
		add_action( 'wp_ajax_moksafowo_ecpay_shipping_print_label', [ __CLASS__, 'ajax_print_label' ] );
		add_action( 'wp_ajax_moksafowo_ecpay_shipping_delete_record', [ __CLASS__, 'ajax_delete_record' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function inject_cvs_address_fields( array $address, string $type, $order ): array {
		if ( 'shipping' !== $type || ! $order instanceof \WC_Order ) {
			return $address;
		}
		$method_id = self::find_moksafowo_method_id( $order );
		if ( '' === $method_id || ! str_contains( $method_id, '_cvs_' ) ) {
			return $address;
		}
		$store_addr = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ADDRESS );
		if ( '' === $store_addr ) {
			return $address;
		}
		if ( empty( $address['address_1'] ) ) {
			$address['address_1'] = $store_addr;
		}
		return $address;
	}

	public static function inject_cvs_shipping_address( string $address, $raw_address, \WC_Order $order ): string {
		$method_id = self::find_moksafowo_method_id( $order );
		if ( '' === $method_id ) {
			return $address;
		}
		$is_cvs = str_contains( $method_id, '_cvs_' );

		$registry_titles = self::registry_titles();
		$method_title    = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid  = (string) $m->get_method_id();
			$name = (string) $m->get_name();
			if ( isset( $registry_titles[ $mid ] ) ) {
				$method_title = $registry_titles[ $mid ];
			} elseif ( '' !== $name && $name !== $mid ) {
				$method_title = $name;
			} else {
				$method_title = $mid;
			}
			break;
		}
		$name = trim( $order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() );
		}

		$lines = [];

		// HPOS list table 對此 filter 跑 esc_html()，禁 HTML tag；WC 把 <br/> 換成 ', ' 供列表顯示
		if ( $is_cvs ) {
			$store_id   = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ID );
			$store_name = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_NAME );
			$store_addr = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ADDRESS );
			if ( '' === $store_id ) {
				return $address;  // CVS 但無門市資訊 → 返回原值
			}
			$lines[] = esc_html( $store_name ) . ' (' . esc_html( $store_id ) . ')';
			if ( '' !== $store_addr ) {
				$lines[] = esc_html( $store_addr );
			}
		} else {
			foreach ( \Moksafowo\Modules\Address\TwAddress::shipping_address_lines( $order ) as $line ) {
				$lines[] = esc_html( $line );
			}
		}
		if ( '' !== $name ) {
			$lines[] = esc_html( $name );
		}
		if ( '' !== $method_title ) {
			$lines[] = esc_html( $method_title );
		}
		return implode( '<br/>', $lines );
	}

	public static function add_card( array $cards, \WC_Order $order ): array {
		if ( '' === self::find_moksafowo_method_id( $order ) ) {
			return $cards;
		}

		$records = CreateOrder::get_records( $order );

		ob_start();
		?>
		<div class="moksafowo-ecpay-shipping-meta"
			data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>">

			<?php
			if ( ! empty( $records ) ) :
				$order_total = (int) round( (float) $order->get_total() );
				$is_cod      = 'cod' === (string) $order->get_payment_method();
				$a6_subtypes = PrintProxy::A6_SUBTYPES; // 其他物流隱藏 A6 按鈕
				$is_split    = count( $records ) > 1;
				?>
				<?php if ( $is_split ) : ?>
					<p style="margin:0 0 8px;font-size:11px;color:#646970;">
						<?php
						/* translators: %d: package count */
						echo esc_html( sprintf( __( 'This order is split into %d shipments by temperature zone, each printed and tracked on its own.', 'moksa-for-woocommerce' ), count( $records ) ) );
						?>
					</p>
				<?php endif; ?>
				<div class="moksafowo-ecpay-records" style="display:flex;flex-direction:column;gap:8px;margin:0 0 8px;">
					<?php
					foreach ( $records as $r ) :
						$id         = (string) ( $r['id'] ?? '' );
						$mtn        = (string) ( $r['mtn'] ?? '' );
						$pay        = (string) ( $r['cvs_payment_no'] ?? '' );
						$val        = (string) ( $r['cvs_validation_no'] ?? '' );
						$bk         = (string) ( $r['booking_note'] ?? '' );
						$at         = (string) ( $r['created_at'] ?? '' );
						$updated_at = (string) ( $r['updated_at'] ?? '' );
						$rtn_msg    = (string) ( $r['rtn_msg'] ?? '' );
						$subtype    = (string) ( $r['subtype'] ?? '' );
						$type       = (string) ( $r['type'] ?? '' );
						$type_label = self::subtype_label( $subtype );
						$is_cvs     = 'CVS' === $type;
						$show_a6    = in_array( $subtype, $a6_subtypes, true );
						// 舊 records 沒 temp 欄位：UNIMARTFREEZE → 冷凍，其他 → 常溫
						$temp = isset( $r['temp'] ) ? (int) $r['temp'] : 0;
						if ( 0 === $temp ) {
							$temp = 'UNIMARTFREEZE' === $subtype ? 3 : 1;
						}
						$amount          = isset( $r['amount'] ) ? (int) $r['amount'] : $order_total;
						$temp_label      = \Moksafowo\Modules\Shipping\Temp\ProductTemp::label( $temp );
						$temp_pill_color = match ( $temp ) { // 常溫灰、冷藏藍、冷凍紫
							2       => [ '#dbeafe', '#1e40af' ],
							3       => [ '#ede9fe', '#6d28d9' ],
							default => [ '#e5e7eb', '#374151' ],
						};
						/* translators: %d: cash-on-delivery amount in TWD */
						$cod_label = $is_cod ? sprintf( __( 'NT$%d (cash on delivery)', 'moksa-for-woocommerce' ), $amount ) : __( 'No', 'moksa-for-woocommerce' );
						?>
					<details class="moksafowo-ecpay-record"
						data-logistics-id="<?php echo esc_attr( $id ); ?>"
						<?php echo $is_split ? '' : 'open'; ?>>
						<summary>
							<span class="moksafowo-ecpay-record__summary-id"><?php echo esc_html( $id ); ?></span>
							<?php if ( '' !== $type_label ) : ?>
								<span style="background:#dbeafe;color:#1e40af;padding:1px 8px;border-radius:3px;font-size:11px;white-space:nowrap;"><?php echo esc_html( $type_label ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $temp_label ) : ?>
								<span style="background:<?php echo esc_attr( $temp_pill_color[0] ); ?>;color:<?php echo esc_attr( $temp_pill_color[1] ); ?>;padding:1px 8px;border-radius:3px;font-size:11px;white-space:nowrap;"><?php echo esc_html( $temp_label ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $rtn_msg ) : ?>
								<span class="moksafowo-ecpay-record__summary-status"><?php echo esc_html( $rtn_msg ); ?></span>
							<?php endif; ?>
						</summary>
						<div class="moksafowo-ecpay-record__body">
						<?php if ( $is_cvs && '' !== $pay ) : ?>
							<p style="margin:.2em 0;"><strong><?php esc_html_e( 'Shipping number:', 'moksa-for-woocommerce' ); ?></strong><span style="font-family:monospace;word-break:break-all;"><?php echo esc_html( $pay ); ?>
							<?php
							if ( '' !== $val ) :
								?>
								/ <?php echo esc_html( $val ); ?><?php endif; ?></span></p>
						<?php elseif ( ! $is_cvs && '' !== $bk ) : ?>
							<p style="margin:.2em 0;"><strong><?php esc_html_e( 'Waybill number:', 'moksa-for-woocommerce' ); ?></strong><span style="font-family:monospace;word-break:break-all;"><?php echo esc_html( $bk ); ?></span></p>
						<?php endif; ?>
						<?php /* translators: %d: declared value amount in TWD */ ?>
						<p style="margin:.2em 0;"><strong><?php esc_html_e( 'Declared value:', 'moksa-for-woocommerce' ); ?></strong><?php echo esc_html( sprintf( __( 'NT$%d', 'moksa-for-woocommerce' ), $amount ) ); ?></p>
						<p style="margin:.2em 0;"><strong><?php esc_html_e( 'Amount to collect:', 'moksa-for-woocommerce' ); ?></strong><?php echo esc_html( $cod_label ); ?></p>
						<?php
						if ( '' !== $at ) :
							$days_ago = self::days_since( $at );
							?>
							<p style="margin:.2em 0;">
								<strong><?php esc_html_e( 'Created:', 'moksa-for-woocommerce' ); ?></strong><?php echo esc_html( $at ); ?>
								<?php
								if ( null !== $days_ago ) :
									$pill_color = $days_ago >= 14 ? '#d63638' : ( $days_ago >= 7 ? '#dba617' : '#646970' );
									?>
									<span style="color:<?php echo esc_attr( $pill_color ); ?>;font-size:11px;margin-left:4px;">
										<?php
										if ( 0 === $days_ago ) {
											esc_html_e( '(today)', 'moksa-for-woocommerce' );
										} else {
											/* translators: %d: 天數 */
											echo esc_html( sprintf( __( '(%d days ago)', 'moksa-for-woocommerce' ), $days_ago ) );
										}
										?>
									</span>
								<?php endif; ?>
							</p>
						<?php endif; ?>
						<p style="margin:.2em 0;">
							<strong><?php esc_html_e( 'Status updated:', 'moksa-for-woocommerce' ); ?></strong>
							<?php if ( '' !== $updated_at && $updated_at !== $at ) : ?>
								<?php echo esc_html( $updated_at ); ?>
							<?php else : ?>
								<em style="color:#646970;"><?php esc_html_e( 'Not updated yet', 'moksa-for-woocommerce' ); ?></em>
							<?php endif; ?>
						</p>
						<?php if ( '' !== $rtn_msg ) : ?>
							<p style="margin:.2em 0;color:#646970;"><strong><?php esc_html_e( 'Status:', 'moksa-for-woocommerce' ); ?></strong><?php echo esc_html( $rtn_msg ); ?></p>
						<?php endif; ?>
						<?php
						$tracking_info = TrackingLink::for_ecpay_record( $r );
						if ( null !== $tracking_info ) :
							?>
							<div style="margin-top:8px;">
								<?php echo wp_kses( TrackingLink::render_button_html( $tracking_info ), TrackingLink::kses_allowlist() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses-filtered above. ?>
							</div>
							<?php
						endif;
						?>
						<div style="display:flex;gap:4px;justify-content:flex-end;margin-top:10px;">
							<button type="button"
	class="button button-small moksafowo-ecpay-shipping-print"
								data-logistics-id="<?php echo esc_attr( $id ); ?>"
								data-mode="1"
								title="<?php esc_attr_e( 'Print A4', 'moksa-for-woocommerce' ); ?>"><?php esc_html_e( 'A4', 'moksa-for-woocommerce' ); ?></button>
							<?php if ( $show_a6 ) : ?>
							<button type="button"
	class="button button-small moksafowo-ecpay-shipping-print"
								data-logistics-id="<?php echo esc_attr( $id ); ?>"
								data-mode="2"
								title="<?php esc_attr_e( 'Print A6 label', 'moksa-for-woocommerce' ); ?>"><?php esc_html_e( 'A6', 'moksa-for-woocommerce' ); ?></button>
							<?php endif; ?>
							<button type="button"
	class="button button-small button-link-delete moksafowo-ecpay-shipping-delete-record"
								data-logistics-id="<?php echo esc_attr( $id ); ?>"
								title="<?php esc_attr_e( 'Delete this record', 'moksa-for-woocommerce' ); ?>"
								style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'moksa-for-woocommerce' ); ?></button>
						</div>
						</div>
					</details>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p style="margin:8px 0 0;">
				<button type="button" class="button button-primary moksafowo-ecpay-shipping-create"<?php echo empty( $records ) ? '' : ' data-has-records="1"'; ?>>
					<?php echo empty( $records ) ? esc_html__( 'Create shipment', 'moksa-for-woocommerce' ) : esc_html__( 'Recreate shipment', 'moksa-for-woocommerce' ); ?>
				</button>
			</p>

			<?php wp_nonce_field( self::NONCE_ACTION, 'moksafowo_ecpay_shipping_nonce' ); ?>
		</div>
		<?php
		$cards[] = [
			'slot'  => 'shipping',
			'title' => __( 'Shipping details', 'moksa-for-woocommerce' ),
			'html'  => (string) ob_get_clean(),
		];
		return $cards;
	}

	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}
		$handle  = 'moksafowo-ecpay-shipping-admin';
		$js_path = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/EcpayShipping/assets/js/admin-meta-box.js';
		$ver     = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MOKSAFOWO_VERSION;
		wp_register_script(
			$handle,
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/EcpayShipping/assets/js/admin-meta-box.js',
			[ 'jquery' ],
			$ver,
			true
		);
		wp_localize_script(
			$handle,
			'moksafowo_ecpay_shipping_admin',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'i18n'     => [
					'creating'         => __( 'Creating…', 'moksa-for-woocommerce' ),
					'create_ok'        => __( 'The shipment was created. Refresh the page to see the full details.', 'moksa-for-woocommerce' ),
					'create_fail'      => __( 'Could not create:', 'moksa-for-woocommerce' ),
					'recreate_confirm' => __( 'This order already has shipments, so only the missing ones will be created. To rebuild them all, delete the existing records first. Continue?', 'moksa-for-woocommerce' ),
					'no_order'         => __( 'The order could not be found.', 'moksa-for-woocommerce' ),
					'delete_confirm'   => __( 'Delete this shipment record?', 'moksa-for-woocommerce' ),
					'delete_ok'        => __( 'Deleted. Refreshing the page.', 'moksa-for-woocommerce' ),
					'delete_fail'      => __( 'Could not delete:', 'moksa-for-woocommerce' ),
					'print_fail'       => __( 'Could not print:', 'moksa-for-woocommerce' ),
					'printing'         => __( 'Printing…', 'moksa-for-woocommerce' ),
					'unknown_error'    => __( 'Something went wrong. Please try again later, or check the logs.', 'moksa-for-woocommerce' ),
					'ajax_error'       => __( 'Connection error. Please try again later.', 'moksa-for-woocommerce' ),
				],
			]
		);
		wp_enqueue_script( $handle );
		wp_enqueue_script( 'moksafowo-tracking-copy' );

		$css = '.moksafowo-ecpay-record summary{cursor:pointer;list-style:none;padding:10px 12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;font-size:12px;}'
			. '.moksafowo-ecpay-record[open] summary{border-bottom-left-radius:0;border-bottom-right-radius:0;border-bottom:0;}'
			. '.moksafowo-ecpay-record summary::-webkit-details-marker{display:none;}'
			. ".moksafowo-ecpay-record summary::before{content:'\\25B6';margin-right:2px;font-size:9px;color:#646970;display:inline-block;transition:transform .15s;flex-shrink:0;}"
			. '.moksafowo-ecpay-record[open] summary::before{transform:rotate(90deg);}'
			. '.moksafowo-ecpay-record__body{background:#f6f7f7;border:1px solid #dcdcde;border-top:0;border-bottom-left-radius:4px;border-bottom-right-radius:4px;padding:0 12px 10px;font-size:12px;line-height:1.5;}'
			. '.moksafowo-ecpay-record summary > * + *{margin-left:0;}'
			. '.moksafowo-ecpay-record__summary-id{font-family:monospace;font-weight:600;color:#0f172a;}'
			. '.moksafowo-ecpay-record__summary-status{margin-left:auto;color:#64748b;font-size:11px;}';
		wp_register_style( 'moksafowo-ecpay-shipping-admin', false, [], $ver );
		wp_enqueue_style( 'moksafowo-ecpay-shipping-admin' );
		wp_add_inline_style( 'moksafowo-ecpay-shipping-admin', $css );
	}

	private static function days_since( string $datetime ): ?int {
		try {
			$tz   = wp_timezone();
			$past = new \DateTimeImmutable( $datetime, $tz );
			$now  = new \DateTimeImmutable( current_time( 'mysql' ), $tz );
			$diff = $past->diff( $now );
			if ( $diff->invert ) {
				return null; // 未來時間
			}
			return (int) $diff->days;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private static function subtype_label( string $subtype ): string {
		$map = [
			'UNIMARTC2C'    => __( '7-ELEVEN pickup', 'moksa-for-woocommerce' ),
			'FAMIC2C'       => __( 'FamilyMart pickup', 'moksa-for-woocommerce' ),
			'HILIFEC2C'     => __( 'Hi-Life pickup', 'moksa-for-woocommerce' ),
			'OKMARTC2C'     => __( 'OK Mart pickup', 'moksa-for-woocommerce' ),
			'UNIMART'       => __( '7-ELEVEN bulk', 'moksa-for-woocommerce' ),
			'UNIMARTFREEZE' => __( '7-ELEVEN frozen', 'moksa-for-woocommerce' ),
			'FAMI'          => __( 'FamilyMart bulk', 'moksa-for-woocommerce' ),
			'HILIFE'        => __( 'Hi-Life bulk', 'moksa-for-woocommerce' ),
			'OKMART'        => __( 'OK Mart bulk', 'moksa-for-woocommerce' ),
			'TCAT'          => __( 'T-Cat home delivery', 'moksa-for-woocommerce' ),
			'POST'          => __( 'Chunghwa Post', 'moksa-for-woocommerce' ),
		];
		return $map[ $subtype ] ?? $subtype;
	}

	public static function ajax_create_order(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}

		$result = CreateOrder::run( $order );
		if ( $result['ok'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	public static function ajax_print_label(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}

		$specific_id = isset( $_POST['logistics_id'] ) ? sanitize_text_field( wp_unslash( $_POST['logistics_id'] ) ) : '';
		$mode        = isset( $_POST['mode'] ) && '2' === sanitize_text_field( wp_unslash( $_POST['mode'] ) ) ? '2' : '1';
		if ( '' !== $specific_id ) {
			$records = CreateOrder::get_records( $order );
			$found   = null;
			foreach ( $records as $r ) {
				if ( ( $r['id'] ?? '' ) === $specific_id ) {
					$found = $r;
					break;
				}
			}
			if ( null === $found ) {
				wp_send_json_error( [ 'message' => __( 'That shipment could not be found.', 'moksa-for-woocommerce' ) ], 404 );
			}
			$result = PrintLabel::build_for_ids( [ (string) $found['id'] ], (string) $found['subtype'], $order, $mode );
		} else {
			$result = PrintLabel::build( $order, $mode );
		}

		if ( $result['ok'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	public static function ajax_delete_record(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id     = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$logistics_id = isset( $_POST['logistics_id'] ) ? sanitize_text_field( wp_unslash( $_POST['logistics_id'] ) ) : '';
		$order        = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}
		if ( '' === $logistics_id ) {
			wp_send_json_error( [ 'message' => __( 'The shipping ID is missing.', 'moksa-for-woocommerce' ) ], 400 );
		}
		$ok = CreateOrder::delete_record( $order, $logistics_id );
		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => __( 'That shipment record could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}
		wp_send_json_success( [ 'message' => 'deleted' ] );
	}
}
