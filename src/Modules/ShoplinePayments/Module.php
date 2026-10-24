<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments;

use Moksafowo\Modules\Shared\AbstractGatewayModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractGatewayModule {

	public function slug(): string {
		return 'shopline_payments';
	}

	public function label(): string {
		return __( 'Shopline Payments — credit card, Apple Pay, Google Pay, LINE Pay and JKOPAY, on a hosted checkout', 'moksa-for-woocommerce' );
	}

	public function name(): string {
		return __( 'Shopline Payments', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( 'Credit card and mobile payments, on a redirected payment page', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( 'Credit card', 'moksa-for-woocommerce' ),
			__( 'Apple Pay', 'moksa-for-woocommerce' ),
			__( 'Google Pay', 'moksa-for-woocommerce' ),
			__( 'LINE Pay', 'moksa-for-woocommerce' ),
			__( 'JKOPAY', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'shopline-payments';
	}

	public static function gateway_map(): array {
		return [
			Gateways\SessionGateway::GATEWAY_ID => Gateways\SessionGateway::class,
			Gateways\CardGateway::GATEWAY_ID    => Gateways\CardGateway::class,
		];
	}

	protected static function blocks_method_class(): string {
		return Blocks\ShoplinePaymentsBlocksMethod::class;
	}

	protected static function uses_allowlist(): bool {
		return false;
	}

	protected function register_webhooks(): void {
		add_action( 'woocommerce_api_moksafowo_shopline_payments', [ Api\WebhookHandler::class, 'handle' ] );
	}

	protected function boot_extras(): void {
		SubscriptionRenewal::boot();
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_card_assets' ], 20 );
		add_filter(
			'moksafowo_payment_query_handlers',
			static function ( array $h ): array {
				$h['moksafowo_shopline'] = [ __CLASS__, 'query_payment_status' ];
				return $h;
			}
		);
	}

	/** 傳統結帳頁的內嵌式信用卡欄位（區塊結帳由 ShoplinePaymentsBlocksMethod 載入）。 */
	public static function enqueue_card_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) ) {
			return;
		}
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
		$gateway  = $gateways[ Gateways\CardGateway::GATEWAY_ID ] ?? null;
		if ( ! $gateway instanceof Gateways\CardGateway || 'yes' !== $gateway->enabled || '' === Api\Helper::client_key() ) {
			return;
		}

		wp_register_script( 'moksafowo-slp-sdk', Api\Helper::SDK_URL, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- 官方 CDN SDK，版本由路徑 /v1/ 決定。
		$path = MOKSAFOWO_PLUGIN_DIR . 'assets/public/moksafowo-slp-card.js';
		wp_enqueue_script(
			'moksafowo-slp-card',
			MOKSAFOWO_PLUGIN_URL . 'assets/public/moksafowo-slp-card.js',
			[ 'jquery', 'moksafowo-slp-sdk' ],
			file_exists( $path ) ? MOKSAFOWO_VERSION . '.' . filemtime( $path ) : MOKSAFOWO_VERSION,
			true
		);
		wp_localize_script(
			'moksafowo-slp-card',
			'moksafowoSlpCard',
			[
				'gatewayId'  => Gateways\CardGateway::GATEWAY_ID,
				'clientKey'  => Api\Helper::client_key(),
				'merchantId' => Api\Helper::merchant_id(),
				'env'        => Api\Helper::sdk_env(),
				'amount'     => (int) round( (float) ( WC()->cart ? WC()->cart->get_total( 'edit' ) : 0 ) * 100 ),
				'bindCard'   => \Moksafowo\Compatibility\Subscriptions::active() && class_exists( 'WC_Subscriptions_Cart' ) && \WC_Subscriptions_Cart::cart_contains_subscription(),
				'i18n'       => [
					'loadError' => __( 'The card form could not be loaded. Please refresh the page or choose another payment method.', 'moksa-for-woocommerce' ),
					'payError'  => __( 'The card payment did not go through. Please check the card and try again.', 'moksa-for-woocommerce' ),
				],
			]
		);
	}

	/**
	 * 向 Shopline Payments 查這筆 session 的權威狀態。
	 *
	 * @return array{ok:bool,message:string,lines:array<string,string>}
	 */
	public static function query_payment_status( \WC_Order $order ): array {
		$sid = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::SLP_SESSION_ID );
		if ( '' === $sid ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no Shopline Payments session to look up yet.', 'moksa-for-woocommerce' ),
				'lines'   => [],
			];
		}

		$res = Api\Client::query_session( $sid );
		if ( empty( $res['ok'] ) ) {
			return [
				'ok'      => false,
				'message' => (string) ( $res['message'] ?? __( 'Shopline Payments could not be reached.', 'moksa-for-woocommerce' ) ),
				'lines'   => [],
			];
		}

		$d      = (array) ( $res['data'] ?? [] );
		$status = '' !== (string) ( $d['status'] ?? '' ) ? Api\Helper::status_label( (string) $d['status'] ) . ' (' . $d['status'] . ')' : '';
		$amount = (string) ( $d['amount']['value'] ?? '' );
		return [
			'ok'      => true,
			'message' => '' !== $status ? $status : 'OK',
			'lines'   => array_filter(
				[
					__( 'Status', 'moksa-for-woocommerce' )         => $status,
					// 金額以「元 × 100」表示。
					__( 'Amount', 'moksa-for-woocommerce' )         => '' !== $amount ? 'NT$' . wc_format_decimal( (int) $amount / 100, 0 ) : '',
					__( 'Payment type', 'moksa-for-woocommerce' )   => Api\Helper::method_label( (string) ( $d['paymentMethod'] ?? '' ) ),
					__( 'Transaction ID', 'moksa-for-woocommerce' ) => (string) ( $d['tradeOrderId'] ?? '' ),
				],
				static fn( string $v ): bool => '' !== $v
			),
		];
	}
}
