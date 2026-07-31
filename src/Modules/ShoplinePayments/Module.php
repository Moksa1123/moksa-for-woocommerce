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
}
