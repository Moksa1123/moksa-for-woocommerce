<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\NewebpayShipping\Frontend;

use Moksafowo\Modules\NewebpayShipping\Module;
use Moksafowo\Modules\Shipping\Tracking\TrackingLink;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 顧客端訂單頁的藍新物流卡片。
 *
 * 綠界／速買配／統一金流都有，只有藍新沒有 —— 顧客登入看訂單時查不到門市與物流
 * 編號。版面沿用共用的 .moksafowo-shipping-card，跟其他三家一致。
 *
 * 藍新沒有溫層、也沒有多筆物流單（資料平鋪在 order meta），所以不需要拆單那段。
 */
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

	/**
	 * @param mixed $order 由 WooCommerce 傳入，不保證是 WC_Order。
	 */
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

		$lgs_no     = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_LGS_NO );
		$store_id   = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_STORE_ID );
		$store_name = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_NAME );
		$store_addr = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ADDRESS );
		if ( '' === $lgs_no && '' === $store_id ) {
			return;
		}

		$tracking_info = TrackingLink::for_newebpay_record(
			[
				'ship_type' => (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_SHIP_TYPE ),
				'lgs_no'    => $lgs_no,
			]
		);
		$status_text   = wc_get_order_status_name( $order->get_status() );
		$status_tone   = self::status_tone( $order->get_status() );
		?>
		<section class="moksafowo-shipping-card" aria-label="<?php esc_attr_e( 'Shipping details', 'moksa-for-woocommerce' ); ?>">

			<header class="moksafowo-shipping-card__head">
				<h2 class="moksafowo-shipping-card__title">
					<span class="moksafowo-shipping-card__title-text"><?php esc_html_e( 'Shipping details', 'moksa-for-woocommerce' ); ?></span>
					<span class="moksafowo-shipping-card__subtitle"><?php esc_html_e( 'NewebPay convenience store pickup', 'moksa-for-woocommerce' ); ?></span>
				</h2>
				<span class="moksafowo-shipping-card__pill moksafowo-shipping-card__pill--<?php echo esc_attr( $status_tone ); ?>">
					<?php echo esc_html( $status_text ); ?>
				</span>
			</header>

			<div class="moksafowo-shipping-card__body">
				<?php if ( '' !== $store_name || '' !== $store_id ) : ?>
					<div class="moksafowo-shipping-card__row">
						<span class="moksafowo-shipping-card__label"><?php esc_html_e( 'Pickup store', 'moksa-for-woocommerce' ); ?></span>
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
				<?php endif; ?>

				<?php if ( '' !== $lgs_no ) : ?>
					<div class="moksafowo-shipping-card__row">
						<span class="moksafowo-shipping-card__label"><?php esc_html_e( 'Shipping ID', 'moksa-for-woocommerce' ); ?></span>
						<span class="moksafowo-shipping-card__value" style="display:flex;flex-direction:column;gap:6px;">
							<span class="moksafowo-shipping-card__code"><?php echo esc_html( $lgs_no ); ?></span>
							<?php if ( null !== $tracking_info ) : ?>
								<?php echo wp_kses( TrackingLink::render_button_html( $tracking_info ), TrackingLink::kses_allowlist() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses-filtered above. ?>
							<?php endif; ?>
						</span>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
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
