<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Admin;

use MoksaWeb\Mowc\Modules\EcpayShipping\Module;
use MoksaWeb\Mowc\Modules\EcpayShipping\Operations\CreateOrder;
use MoksaWeb\Mowc\Modules\EcpayShipping\Operations\PrintLabel;
use MoksaWeb\Mowc\Modules\Shared\Admin\OrderInfoLayout;
use MoksaWeb\Mowc\Modules\Shipping\Tracking\TrackingLink;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox {

	private const NONCE_ACTION = 'moksafowo_ecpay_shipping_admin';
	private const CAPABILITY   = 'edit_shop_orders';

	
	private static array $method_id_cache = [];

	
	private static ?array $registry_titles_cache = null;

	private static function find_mowp_method_id( \WC_Order $order ): string {
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
			foreach ( \MoksaWeb\Mowc\Modules\Shipping\Admin\BatchPrintRegistry::all() as $entry ) {
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
		// 三欄 footer：priority 20 = 物流（中）— 順序「金流(10) 物流(20) 發票(30)」
		add_filter( 'moksafowo_order_info_cards', [ __CLASS__, 'add_card' ], 20, 2 );
		add_action( 'wp_ajax_moksafowo_ecpay_shipping_create_order', [ __CLASS__, 'ajax_create_order' ] );
		add_action( 'wp_ajax_moksafowo_ecpay_shipping_print_label', [ __CLASS__, 'ajax_print_label' ] );
		add_action( 'wp_ajax_moksafowo_ecpay_shipping_delete_record', [ __CLASS__, 'ajax_delete_record' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function inject_cvs_address_fields( array $address, string $type, $order ): array {
		if ( 'shipping' !== $type || ! $order instanceof \WC_Order ) {
			return $address;
		}
		$method_id = self::find_mowp_method_id( $order );
		if ( '' === $method_id || ! str_contains( $method_id, '_cvs_' ) ) {
			return $address;
		}
		$store_addr = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ADDRESS );
		if ( '' === $store_addr ) {
			return $address;
		}
		// 只在 address_1 為空時注入（不覆蓋管理員手動輸入的值）
		if ( empty( $address['address_1'] ) ) {
			$address['address_1'] = $store_addr;
		}
		return $address;
	}

	public static function inject_cvs_shipping_address( string $address, $raw_address, \WC_Order $order ): string {
		// 只處理 ECPay 物流訂單（CVS 或 HOME）
		$method_id = self::find_mowp_method_id( $order );
		if ( '' === $method_id ) {
			return $address;
		}
		$is_cvs = str_contains( $method_id, '_cvs_' );

		// 拿運送方式中文標題 — registry titles 優先
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
		// 收件人
		$name = trim( $order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() );
		}

		$lines = [];
		if ( '' !== $name ) {
			$lines[] = esc_html( $name );
		}
		if ( '' !== $method_title ) {
			$lines[] = esc_html( $method_title );
		}

		// 注意：HPOS list table 的「運送至」column 會把這個 filter 回傳值跑 esc_html()，
		// 所以 inner 不能放任何 HTML tag（包含 <small>），否則會被 escape 顯示成原始字串。
		// 用純文字 + <br/> 分隔 — WC 會 preg_replace 把 <br/> 換成 ', ' 給 list 顯示。
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
			// HOME — 用實體運送地址。走 TwAddress::format_shipping_address 統一處理
			// 縣市英文代碼 → 中文（state_label）+ 鄉鎮市區（_shipping_mowp/district）。
			$formatted = \MoksaWeb\Mowc\Modules\Address\TwAddress::format_shipping_address( $order );
			if ( '' !== $formatted ) {
				$lines[] = esc_html( $formatted );
			}
		}
		return implode( '<br/>', $lines );
	}

	public static function add_card( array $cards, \WC_Order $order ): array {
		// 不是 ECPay 物流訂單就不顯示（避免在所有訂單下方都印出 section）
		if ( '' === self::find_mowp_method_id( $order ) ) {
			return $cards;
		}

		$records = CreateOrder::get_records( $order );

		ob_start();
		?>
		<div class="moksafowo-ecpay-shipping-meta"
			data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>">

			<?php if ( ! empty( $records ) ) :
				$order_total = (int) round( (float) $order->get_total() );
				$is_cod      = 'cod' === (string) $order->get_payment_method();
				// A6 只有 7-11 + POST 支援，其他物流隱藏 A6 按鈕。
				$a6_subtypes = [ 'UNIMARTC2C', 'POST' ];
				// 多筆 records = 「同訂單拆 N 包」。
				$is_split = count( $records ) > 1;
			?>
				<?php if ( $is_split ) : ?>
					<p style="margin:0 0 8px;font-size:11px;color:#646970;">
						<?php
						/* translators: %d: package count */
						echo esc_html( sprintf( __( '本訂單依商品溫層拆成 %d 張物流單，每張獨立列印與追蹤。', 'mo-ectools' ), count( $records ) ) );
						?>
					</p>
				<?php endif; ?>
				<div class="moksafowo-ecpay-records" style="display:flex;flex-direction:column;gap:8px;margin:0 0 8px;">
					<?php foreach ( $records as $r ) :
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
						// Phase C 後新增：每筆 record 各自的溫層 + 申報金額。
						// 舊 records 沒 temp 欄位時 fallback subtype 推（UNIMARTFREEZE → 冷凍、其他 → 常溫），
						// 確保所有 records 都顯示溫層 pill。
						$temp = isset( $r['temp'] ) ? (int) $r['temp'] : 0;
						if ( 0 === $temp ) {
							$temp = 'UNIMARTFREEZE' === $subtype ? 3 : 1;
						}
						$amount     = isset( $r['amount'] ) ? (int) $r['amount'] : $order_total;
						$temp_label = \MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTemp::label( $temp );
						// 溫層 pill 顏色（常溫灰、冷藏藍、冷凍紫）
						$temp_pill_color = match ( $temp ) {
							2       => [ '#dbeafe', '#1e40af' ],
							3       => [ '#ede9fe', '#6d28d9' ],
							default => [ '#e5e7eb', '#374151' ],
						};
						/* translators: %d: cash-on-delivery amount in TWD */
						$cod_label = $is_cod ? sprintf( __( 'NT$%d (貨到付款)', 'mo-ectools' ), $amount ) : __( '否', 'mo-ectools' );
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
							<p style="margin:.2em 0;"><strong><?php esc_html_e( '寄貨編號：', 'mo-ectools' ); ?></strong><span style="font-family:monospace;word-break:break-all;"><?php echo esc_html( $pay ); ?><?php if ( '' !== $val ) : ?> / <?php echo esc_html( $val ); ?><?php endif; ?></span></p>
						<?php elseif ( ! $is_cvs && '' !== $bk ) : ?>
							<p style="margin:.2em 0;"><strong><?php esc_html_e( '託運單號：', 'mo-ectools' ); ?></strong><span style="font-family:monospace;word-break:break-all;"><?php echo esc_html( $bk ); ?></span></p>
						<?php endif; ?>
						<?php /* translators: %d: declared value amount in TWD */ ?>
						<p style="margin:.2em 0;"><strong><?php esc_html_e( '申報價值：', 'mo-ectools' ); ?></strong><?php echo esc_html( sprintf( __( 'NT$%d', 'mo-ectools' ), $amount ) ); ?></p>
						<p style="margin:.2em 0;"><strong><?php esc_html_e( '代收貨款：', 'mo-ectools' ); ?></strong><?php echo esc_html( $cod_label ); ?></p>
						<?php if ( '' !== $at ) :
							$days_ago = self::days_since( $at );
						?>
							<p style="margin:.2em 0;">
								<strong><?php esc_html_e( '建立時間：', 'mo-ectools' ); ?></strong><?php echo esc_html( $at ); ?>
								<?php if ( null !== $days_ago ) :
									$pill_color = $days_ago >= 14 ? '#d63638' : ( $days_ago >= 7 ? '#dba617' : '#646970' );
								?>
									<span style="color:<?php echo esc_attr( $pill_color ); ?>;font-size:11px;margin-left:4px;">
										<?php
										if ( 0 === $days_ago ) {
											esc_html_e( '（今天）', 'mo-ectools' );
										} else {
											/* translators: %d: 天數 */
											echo esc_html( sprintf( __( '（%d 天前）', 'mo-ectools' ), $days_ago ) );
										}
										?>
									</span>
								<?php endif; ?>
							</p>
						<?php endif; ?>
						<p style="margin:.2em 0;">
							<strong><?php esc_html_e( '狀態更新時間：', 'mo-ectools' ); ?></strong>
							<?php if ( '' !== $updated_at && $updated_at !== $at ) : ?>
								<?php echo esc_html( $updated_at ); ?>
							<?php else : ?>
								<em style="color:#646970;"><?php esc_html_e( '尚未更新', 'mo-ectools' ); ?></em>
							<?php endif; ?>
						</p>
						<?php if ( '' !== $rtn_msg ) : ?>
							<p style="margin:.2em 0;color:#646970;"><strong><?php esc_html_e( '狀態：', 'mo-ectools' ); ?></strong><?php echo esc_html( $rtn_msg ); ?></p>
						<?php endif; ?>
						<?php
						// 貨態查詢按鈕 — 跟前台顧客頁同一套 helper，黑貓直連 / 其他開新分頁
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
								title="<?php esc_attr_e( '列印 A4', 'mo-ectools' ); ?>"><?php esc_html_e( 'A4', 'mo-ectools' ); ?></button>
							<?php if ( $show_a6 ) : ?>
							<button type="button"
	class="button button-small moksafowo-ecpay-shipping-print"
								data-logistics-id="<?php echo esc_attr( $id ); ?>"
								data-mode="2"
								title="<?php esc_attr_e( '列印 A6 標籤機', 'mo-ectools' ); ?>"><?php esc_html_e( 'A6', 'mo-ectools' ); ?></button>
							<?php endif; ?>
							<button type="button"
	class="button button-small button-link-delete moksafowo-ecpay-shipping-delete-record"
								data-logistics-id="<?php echo esc_attr( $id ); ?>"
								title="<?php esc_attr_e( '刪除此筆', 'mo-ectools' ); ?>"
								style="color:#b32d2e;"><?php esc_html_e( '刪除', 'mo-ectools' ); ?></button>
						</div>
						</div>
					</details>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p style="margin:8px 0 0;">
				<button type="button" class="button button-primary moksafowo-ecpay-shipping-create"<?php echo empty( $records ) ? '' : ' data-has-records="1"'; ?>>
					<?php echo empty( $records ) ? esc_html__( '建立物流單', 'mo-ectools' ) : esc_html__( '重新建立物流單', 'mo-ectools' ); ?>
				</button>
			</p>

			<?php wp_nonce_field( self::NONCE_ACTION, 'moksafowo_ecpay_shipping_nonce' ); ?>
		</div>
		<?php
		$cards[] = [
			'slot'  => 'shipping',
			'title' => __( '物流資訊', 'mo-ectools' ),
			'html'  => (string) ob_get_clean(),
		];
		return $cards;
	}

	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}
		$handle = 'moksafowo-ecpay-shipping-admin';
		$js_path = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/EcpayShipping/assets/js/admin-meta-box.js';
		$ver     = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MOKSAFOWO_VERSION;
		wp_register_script(
			$handle,
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/EcpayShipping/assets/js/admin-meta-box.js',
			[ 'jquery' ],
			$ver,
			true
		);
		wp_localize_script( $handle, 'moksafowo_ecpay_shipping_admin', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'i18n'     => [
				'creating'      => __( '建立中…', 'mo-ectools' ),
				'create_ok'     => __( '物流單已建立，重新整理頁面取得完整資訊。', 'mo-ectools' ),
				'create_fail'   => __( '建立失敗：', 'mo-ectools' ),
				'recreate_confirm' => __( '此訂單已有物流單記錄。系統只會為「尚未建立的溫層」補建，已建立的不會重複下單。若要整批重建，請先刪除既有記錄。是否繼續？', 'mo-ectools' ),
				'no_order'      => __( '找不到訂單。', 'mo-ectools' ),
				'delete_confirm' => __( '確定刪除此筆物流單記錄？\n（綠界端不會收到通知，僅刪除網站本地紀錄）', 'mo-ectools' ),
				'delete_ok'     => __( '已刪除，重新整理頁面。', 'mo-ectools' ),
				'delete_fail'   => __( '刪除失敗：', 'mo-ectools' ),
				'print_fail'    => __( '列印失敗：', 'mo-ectools' ),
				'printing'      => __( '列印中…', 'mo-ectools' ),
				'unknown_error' => __( '未知錯誤，請稍後再試或查看記錄。', 'mo-ectools' ),
				'ajax_error'    => __( '連線錯誤，請稍後再試。', 'mo-ectools' ),
			],
		] );
		wp_enqueue_script( $handle );
		// 共用 clipboard JS — admin 頁的「複製貨號」按鈕用（由 Shipping\Module::register_admin_assets 註冊）
		wp_enqueue_script( 'moksafowo-tracking-copy' );

		$css = ".moksafowo-ecpay-record summary{cursor:pointer;list-style:none;padding:10px 12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;font-size:12px;}"
			. ".moksafowo-ecpay-record[open] summary{border-bottom-left-radius:0;border-bottom-right-radius:0;border-bottom:0;}"
			. ".moksafowo-ecpay-record summary::-webkit-details-marker{display:none;}"
			. ".moksafowo-ecpay-record summary::before{content:'\\25B6';margin-right:2px;font-size:9px;color:#646970;display:inline-block;transition:transform .15s;flex-shrink:0;}"
			. ".moksafowo-ecpay-record[open] summary::before{transform:rotate(90deg);}"
			. ".moksafowo-ecpay-record__body{background:#f6f7f7;border:1px solid #dcdcde;border-top:0;border-bottom-left-radius:4px;border-bottom-right-radius:4px;padding:0 12px 10px;font-size:12px;line-height:1.5;}"
			. ".moksafowo-ecpay-record summary > * + *{margin-left:0;}"
			. ".moksafowo-ecpay-record__summary-id{font-family:monospace;font-weight:600;color:#0f172a;}"
			. ".moksafowo-ecpay-record__summary-status{margin-left:auto;color:#64748b;font-size:11px;}";
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
			'UNIMARTC2C' => __( '7-11 取貨', 'mo-ectools' ),
			'FAMIC2C'    => __( '全家取貨', 'mo-ectools' ),
			'HILIFEC2C'  => __( '萊爾富取貨', 'mo-ectools' ),
			'OKMARTC2C'  => __( 'OK 取貨', 'mo-ectools' ),
			'UNIMART'    => __( '7-11 大宗', 'mo-ectools' ),
			'FAMI'       => __( '全家大宗', 'mo-ectools' ),
			'HILIFE'     => __( '萊爾富大宗', 'mo-ectools' ),
			'TCAT'       => __( '黑貓宅配', 'mo-ectools' ),
			'POST'       => __( '中華郵政', 'mo-ectools' ),
		];
		return $map[ $subtype ] ?? $subtype;
	}

	public static function ajax_create_order(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( '找不到訂單。', 'mo-ectools' ) ], 404 );
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
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( '找不到訂單。', 'mo-ectools' ) ], 404 );
		}

		// Optional: 限定特定物流單 ID 列印（每筆 row 的 print 按鈕）
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
				wp_send_json_error( [ 'message' => __( '找不到指定的物流單。', 'mo-ectools' ) ], 404 );
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
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ], 403 );
		}
		$order_id     = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$logistics_id = isset( $_POST['logistics_id'] ) ? sanitize_text_field( wp_unslash( $_POST['logistics_id'] ) ) : '';
		$order        = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( '找不到訂單。', 'mo-ectools' ) ], 404 );
		}
		if ( '' === $logistics_id ) {
			wp_send_json_error( [ 'message' => __( '缺少物流編號。', 'mo-ectools' ) ], 400 );
		}
		$ok = CreateOrder::delete_record( $order, $logistics_id );
		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => __( '找不到此筆物流單記錄。', 'mo-ectools' ) ], 404 );
		}
		wp_send_json_success( [ 'message' => 'deleted' ] );
	}
}
