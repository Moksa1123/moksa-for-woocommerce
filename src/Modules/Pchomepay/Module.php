<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Pchomepay;

use Moksafowo\Modules\Shared\AbstractGatewayModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractGatewayModule {

	public function slug(): string {
		return 'pchomepay';
	}

	public function label(): string {
		return __( 'PChomePay — credit card, Pi Wallet, ATM, convenience store code and store pickup', 'moksa-for-woocommerce' );
	}

	public function name(): string {
		return __( 'PChomePay', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( 'Credit card, Pi Wallet, ATM, convenience store code and pickup with payment', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( 'Credit card', 'moksa-for-woocommerce' ),
			__( 'Pi Wallet', 'moksa-for-woocommerce' ),
			__( 'ATM virtual account', 'moksa-for-woocommerce' ),
			__( 'Convenience store code payment', 'moksa-for-woocommerce' ),
			__( '7-ELEVEN pickup and pay', 'moksa-for-woocommerce' ),
			__( 'FamilyMart pickup and pay', 'moksa-for-woocommerce' ),
			__( 'Hi-Life pickup and pay', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'pchomepay';
	}

	public static function gateway_map(): array {
		return [
			Gateways\Card::GATEWAY_ID      => Gateways\Card::class,
			Gateways\Pi::GATEWAY_ID        => Gateways\Pi::class,
			Gateways\Atm::GATEWAY_ID       => Gateways\Atm::class,
			Gateways\Barcode::GATEWAY_ID   => Gateways\Barcode::class,
			Gateways\Cvs711::GATEWAY_ID    => Gateways\Cvs711::class,
			Gateways\CvsFamily::GATEWAY_ID => Gateways\CvsFamily::class,
			Gateways\CvsHilife::GATEWAY_ID => Gateways\CvsHilife::class,
		];
	}

	protected static function blocks_method_class(): string {
		return Blocks\PchomepayBlocksMethod::class;
	}

	protected function register_webhooks(): void {
		add_action( 'woocommerce_api_moksafowo_pchomepay_payment', [ Api\IpnHandler::class, 'handle' ] );
	}

	protected function boot_extras(): void {
		// 顧客端取號繳費資訊（ATM 虛擬帳號 / 超商代碼 / 條碼）。
		Frontend\CustomerPaymentInfo::init();
	}
}
