<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Frontend;

use Moksafowo\Modules\Address\TwAddress;
use Moksafowo\Modules\PayuniShipping\Operations\CreateOrderUnified;
use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;
use Moksafowo\Modules\PayuniShipping\Utils\ShipType;
use Moksafowo\Modules\Shipping\Tracking\TrackingLink;
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
			if ( PayuniShipping::is_payuni_shipping( $mid ) ) {
				$method_id = $mid;
				break;
			}
		}
		if ( '' === $method_id ) {
			return;
		}

		$records           = CreateOrderUnified::get_records( $order );
		$has_legacy_single = '' !== (string) $order->get_meta( OrderMeta::ShipTradeNo );
		if ( empty( $records ) && ! $has_legacy_single ) {
			return;
		}

		$is_cvs      = PayuniShipping::is_moksafowo_payuni_shipping_cvs( $method_id );
		$carrier     = self::carrier_title( $method_id );
		$status      = $order->get_status();
		$status_text = wc_get_order_status_name( $status );
		$status_tone = self::status_tone( $status );
		$is_split    = count( $records ) > 1;

		// Legacy 單筆無 records：把 single keys 合成成一個 record 走相同 render
		if ( empty( $records ) ) {
			$records = [
				[
					'ship_trade_no' => (string) $order->get_meta( OrderMeta::ShipTradeNo ),
					'odno'          => (string) $order->get_meta( OrderMeta::Odno ),
					'ship_type'     => (string) $order->get_meta( OrderMeta::ShipType ),
					'temp'          => '0',
				],
			];
		}

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
					$store_id   = '';
					$store_name = '';
					$store_addr = '';
					$json_data  = $order->get_meta( OrderMeta::STORE_DATA_JSON );
					if ( ! empty( $json_data ) ) {
						$decoded = json_decode( (string) $json_data, true );
						if ( is_array( $decoded ) ) {
							$store_id   = (string) ( $decoded['id'] ?? '' );
							$store_name = (string) ( $decoded['name'] ?? '' );
							$store_addr = (string) ( $decoded['address'] ?? '' );
						}
					}
					if ( '' === $store_id ) {
						$store_id   = (string) $order->get_meta( OrderMeta::StoreId );
						$store_name = (string) $order->get_meta( OrderMeta::StoreName );
						$store_addr = (string) $order->get_meta( OrderMeta::StoreAddr );
					}
					if ( '' !== $store_id || '' !== $store_name ) :
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
					$ship_trade_no = (string) ( $r['ship_trade_no'] ?? '' );
					$odno          = (string) ( $r['odno'] ?? '' );
					$rec_temp      = (int) ( $r['temp'] ?? 0 );
					$temp_label    = $rec_temp > 0 ? \Moksafowo\Modules\Shipping\Temp\ProductTemp::label( $rec_temp ) : '';
					$tracking_info = TrackingLink::for_payuni_record( $r );
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
							<?php if ( '' !== $ship_trade_no ) : ?>
								<span class="moksafowo-shipping-card__code"><?php echo esc_html( $ship_trade_no ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $odno && $odno !== $ship_trade_no ) : ?>
								<span style="font-size:12px;color:#475569;">
									<?php esc_html_e( '物流商出貨編號：', 'mo-ectools' ); ?>
									<span class="moksafowo-shipping-card__code"><?php echo esc_html( $odno ); ?></span>
								</span>
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
			'moksafowo_payuni_shipping_tcat'              => __( 'PAYUNi — 黑貓宅配', 'mo-ectools' ),
			'moksafowo_payuni_shipping_tcat_normal'       => __( 'PAYUNi — 黑貓宅配 (常溫)', 'mo-ectools' ),
			'moksafowo_payuni_shipping_tcat_refrigerated' => __( 'PAYUNi — 黑貓宅配 (冷藏)', 'mo-ectools' ),
			'moksafowo_payuni_shipping_tcat_frozen'       => __( 'PAYUNi — 黑貓宅配 (冷凍)', 'mo-ectools' ),
			'moksafowo_payuni_shipping_711_c2c'           => __( 'PAYUNi — 7-11 C2C 取貨', 'mo-ectools' ),
			'moksafowo_payuni_shipping_711_b2c'           => __( 'PAYUNi — 7-11 B2C 取貨', 'mo-ectools' ),
			'moksafowo_payuni_shipping_711_c2c_normal'    => __( 'PAYUNi — 7-11 C2C 常溫', 'mo-ectools' ),
			'moksafowo_payuni_shipping_711_c2c_frozen'    => __( 'PAYUNi — 7-11 C2C 冷凍', 'mo-ectools' ),
			'moksafowo_payuni_shipping_711_b2c_normal'    => __( 'PAYUNi — 7-11 B2C 常溫', 'mo-ectools' ),
			'moksafowo_payuni_shipping_711_b2c_frozen'    => __( 'PAYUNi — 7-11 B2C 冷凍', 'mo-ectools' ),
		];
		return $map[ $method_id ] ?? __( 'PAYUNi 物流', 'mo-ectools' );
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
