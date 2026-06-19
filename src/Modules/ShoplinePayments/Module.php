<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\ShoplinePayments;

use MoksaWeb\Mowc\Modules\Shared\AbstractGatewayModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractGatewayModule {

	public function slug(): string {
		return 'shopline_payments';
	}

	public function label(): string {
		return __( 'Shopline Payments — 信用卡 / Apple Pay / Google Pay / LINE Pay / 街口（託管結帳）', 'mo-ectools' );
	}

	public function name(): string {
		return __( 'Shopline Payments', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '信用卡 / 行動支付（轉跳付款頁）', 'mo-ectools' );
	}

	public function methods(): array {
		return [
			__( '信用卡', 'mo-ectools' ),
			__( 'Apple Pay', 'mo-ectools' ),
			__( 'Google Pay', 'mo-ectools' ),
			__( 'LINE Pay', 'mo-ectools' ),
			__( '街口支付', 'mo-ectools' ),
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
