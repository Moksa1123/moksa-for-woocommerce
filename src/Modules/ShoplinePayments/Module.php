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
		add_filter(
			'moksafowo_payment_query_handlers',
			static function ( array $h ): array {
				$h['moksafowo_shopline'] = [ __CLASS__, 'query_payment_status' ];
				return $h;
			}
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

		$d = (array) ( $res['data'] ?? [] );
		return [
			'ok'      => true,
			'message' => (string) ( $d['status'] ?? 'OK' ),
			'lines'   => array_filter(
				[
					__( 'Status', 'moksa-for-woocommerce' )         => (string) ( $d['status'] ?? '' ),
					__( 'Amount', 'moksa-for-woocommerce' )         => (string) ( $d['amount']['value'] ?? '' ),
					__( 'Payment type', 'moksa-for-woocommerce' )   => (string) ( $d['paymentMethod'] ?? '' ),
					__( 'Transaction ID', 'moksa-for-woocommerce' ) => (string) ( $d['tradeOrderId'] ?? '' ),
				],
				static fn( string $v ): bool => '' !== $v
			),
		];
	}
}
