<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Frontend;

use Moksafowo\Modules\Address\TwAddress;
use Moksafowo\Modules\Shipping\Tracking\TrackingLink;
use Moksafowo\Modules\SmilepayShipping\Module;
use Moksafowo\Modules\SmilepayShipping\Operations\CreateOrder;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class CustomerOrderView {

	public static function init(): void {
		add_action( 'woocommerce_order_details_before_order_table', [ __CLASS__, 'render' ], 5, 1 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_copy_script' ], 20 );
	}

	public static function enqueue_copy_script(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		wp_enqueue_style( 'moksafowo-shipping-card' );
		wp_enqueue_script( 'moksafowo-tracking-copy' );
	}

	public static function render( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$method_id = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid = (string) $m->get_method_id();
			if ( isset( Module::method_map()[ $mid ] ) ) {
				$method_id = $mid;
				break;
			}
		}
		if ( '' === $method_id ) {
			return;
		}

		$records = CreateOrder::get_records( $order );
		$smseid  = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_NO );
		if ( empty( $records ) && '' === $smseid ) {
			return;
		}

		$is_cvs      = str_contains( $method_id, '_cvs_' );
		$carrier     = self::carrier_title( $method_id );
		$status      = $order->get_status();
		$status_text = wc_get_order_status_name( $status );
		$status_tone = self::status_tone( $status );

		// Legacy 單筆：合成 1 個 record 用同一個 render path
		if ( empty( $records ) ) {
			$records = [
				[
					'smseid'    => $smseid,
					'pay_no'    => (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_PAY_NO ),
					'track_num' => (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_TRACK_NO ),
					'lgs_type'  => (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_LGS_TYPE ),
					'subtype'   => self::method_to_subtype( $method_id ),
					'temp'      => '0',
				],
			];
		}
		$is_split = count( $records ) > 1;

		?>
		<section class="moksafowo-shipping-card" aria-label="<?php esc_attr_e( '物流資訊', 'mo-ectools' ); ?>">

			<header class="moksafowo-shipping-card__head">
				<h2 class="moksafowo-shipping-card__title">
					<span class="moksafowo-shipping-card__title-text"><?php esc_html_e( '物流資訊', 'mo-ectools' ); ?></span>
					<span class="moksafowo-shipping-card__subtitle"><?php echo esc_html( $carrier ); ?></span>
				</h2>
				<span class="moksafowo-shipping-card__pill moksafowo-shipping-card__pill--<?php echo esc_attr( $status_tone ); ?>">
					<?php echo esc_html( $status_text ); ?>
				</span>
			</header>

			<div class="moksafowo-shipping-card__body">
				<?php
				if ( $is_cvs ) {
					$store_name = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STORE_NAME );
					$store_id   = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STORE_ID );
					$store_addr = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STORE_ADDR );
					if ( '' !== $store_name || '' !== $store_id ) :
						?>
						<div class="moksafowo-shipping-card__row">
							<span class="moksafowo-shipping-card__label"><?php esc_html_e( '取貨門市', 'mo-ectools' ); ?></span>
							<span class="moksafowo-shipping-card__value">
								<?php echo esc_html( $store_name ); ?>
								<?php if ( '' !== $store_id ) : ?>
									<span class="moksafowo-shipping-card__store-id">#<?php echo esc_html( $store_id ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $store_addr ) : ?>
									<span class="moksafowo-shipping-card__store-addr"><?php echo esc_html( $store_addr ); ?></span>
								<?php endif; ?>
							</span>
						</div>
						<?php
					endif;
				} else {
					$address   = TwAddress::format_shipping_address( $order );
					$recipient = trim( $order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name() );
					if ( '' === $recipient ) {
						$recipient = trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() );
					}
					if ( '' !== $recipient ) :
						?>
						<div class="moksafowo-shipping-card__row">
							<span class="moksafowo-shipping-card__label"><?php esc_html_e( '收件人', 'mo-ectools' ); ?></span>
							<span class="moksafowo-shipping-card__value"><?php echo esc_html( $recipient ); ?></span>
						</div>
						<?php
					endif;
					if ( '' !== $address ) :
						?>
						<div class="moksafowo-shipping-card__row">
							<span class="moksafowo-shipping-card__label"><?php esc_html_e( '收件地址', 'mo-ectools' ); ?></span>
							<span class="moksafowo-shipping-card__value"><?php echo esc_html( $address ); ?></span>
						</div>
						<?php
					endif;
				}

				if ( $is_split ) :
					?>
					<div class="moksafowo-shipping-card__row" style="grid-template-columns:1fr;border-bottom:1px dashed #f1f5f9;">
						<span class="moksafowo-shipping-card__label" style="font-size:12px;">
							<?php
							/* translators: %d: package count */
							echo esc_html( sprintf( __( '本訂單依商品溫層拆成 %d 張物流單', 'mo-ectools' ), count( $records ) ) );
							?>
						</span>
					</div>
					<?php
				endif;

				foreach ( $records as $r ) :
					$track_num     = (string) ( $r['track_num'] ?? '' );
					$pay_no        = (string) ( $r['pay_no'] ?? '' );
					$rec_temp      = (int) ( $r['temp'] ?? 0 );
					$temp_label    = $rec_temp > 0 ? \Moksafowo\Modules\Shipping\Temp\ProductTemp::label( $rec_temp ) : '';
					$tracking_info = TrackingLink::for_smilepay_record( $r );
					$primary       = '' !== $track_num ? $track_num : $pay_no;
					?>
					<div class="moksafowo-shipping-card__row">
						<span class="moksafowo-shipping-card__label">
							<?php
							echo esc_html__( '物流編號', 'mo-ectools' );
							if ( $is_split && '' !== $temp_label ) {
								echo '<br><span style="font-size:11px;color:#94a3b8;">' . esc_html( $temp_label ) . '</span>';
							}
							?>
						</span>
						<span class="moksafowo-shipping-card__value" style="display:flex;flex-direction:column;gap:6px;">
							<?php if ( '' !== $primary ) : ?>
								<span class="moksafowo-shipping-card__code"><?php echo esc_html( $primary ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $tracking_info ) : ?>
								<?php echo wp_kses( TrackingLink::render_button_html( $tracking_info ), TrackingLink::kses_allowlist() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses-filtered above. ?>
							<?php endif; ?>
						</span>
					</div>
					<?php
				endforeach;
				?>
			</div>
		</section>
		<?php
	}

	private static function carrier_title( string $method_id ): string {
		$map = [
			'moksafowo_smilepay_shipping_cvs_711'      => __( '速買配 — 7-11 取貨', 'mo-ectools' ),
			'moksafowo_smilepay_shipping_cvs_fami'     => __( '速買配 — 全家取貨', 'mo-ectools' ),
			'moksafowo_smilepay_shipping_tcat'         => __( '速買配 — 黑貓宅配', 'mo-ectools' ),
			'moksafowo_smilepay_shipping_tcat_normal'  => __( '速買配 — 黑貓宅配 (常溫)', 'mo-ectools' ),
			'moksafowo_smilepay_shipping_tcat_refrige' => __( '速買配 — 黑貓宅配 (冷藏)', 'mo-ectools' ),
			'moksafowo_smilepay_shipping_tcat_freeze'  => __( '速買配 — 黑貓宅配 (冷凍)', 'mo-ectools' ),
		];
		return $map[ $method_id ] ?? __( '速買配物流', 'mo-ectools' );
	}

	private static function method_to_subtype( string $method_id ): string {
		if ( str_contains( $method_id, '_cvs_711' ) ) {
			return 'UNIMART';
		}
		if ( str_contains( $method_id, '_cvs_fami' ) ) {
			return 'FAMI';
		}
		return 'TCAT';
	}

	private static function status_tone( string $status ): string {
		$status = ltrim( $status, 'wc-' );
		switch ( $status ) {
			case 'moksa-shipped':
				return 'blue';
			case 'moksa-cvs-arrived':
			case 'moksa-store-closed':
				return 'amber';
			case 'completed':
				return 'green';
			case 'failed':
			case 'cancelled':
			case 'refunded':
			case 'moksafowo-failed':
				return 'rose';
			default:
				return 'slate';
		}
	}
}
