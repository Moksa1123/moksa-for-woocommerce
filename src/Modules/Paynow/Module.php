<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow;

use Moksafowo\Modules\Shared\AbstractGatewayModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractGatewayModule {

	public function slug(): string {
		return 'paynow';
	}

	public function label(): string {
		return __( 'PayNow — credit card, instalments, WebATM, ATM, convenience store barcode, ibon, FamiPort, iCash and UnionPay', 'moksa-for-woocommerce' );
	}

	public function name(): string {
		return __( 'PayNow', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( 'Credit card, ATM, convenience store barcode, ibon, FamiPort, iCash and UnionPay', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( 'Credit card', 'moksa-for-woocommerce' ),
			__( 'Credit card instalments', 'moksa-for-woocommerce' ),
			__( 'WebATM', 'moksa-for-woocommerce' ),
			__( 'ATM virtual account', 'moksa-for-woocommerce' ),
			__( 'Convenience store barcode payment', 'moksa-for-woocommerce' ),
			__( 'ibon code', 'moksa-for-woocommerce' ),
			__( 'FamiPort code', 'moksa-for-woocommerce' ),
			__( 'iCash wallet', 'moksa-for-woocommerce' ),
			__( 'UnionPay', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'paynow';
	}

	public static function gateway_map(): array {
		return [
			Gateways\Credit::GATEWAY_ID            => Gateways\Credit::class,
			Gateways\CreditInstallment::GATEWAY_ID => Gateways\CreditInstallment::class,
			Gateways\Webatm::GATEWAY_ID            => Gateways\Webatm::class,
			Gateways\Atm::GATEWAY_ID               => Gateways\Atm::class,
			Gateways\Cvs::GATEWAY_ID               => Gateways\Cvs::class,
			Gateways\Ibon::GATEWAY_ID              => Gateways\Ibon::class,
			Gateways\FamiPort::GATEWAY_ID          => Gateways\FamiPort::class,
			Gateways\Icash::GATEWAY_ID             => Gateways\Icash::class,
			Gateways\Unionpay::GATEWAY_ID          => Gateways\Unionpay::class,
		];
	}

	protected static function blocks_method_class(): string {
		return Blocks\PaynowBlocksMethod::class;
	}

	protected function register_webhooks(): void {
		add_action( 'woocommerce_api_moksafowo_paynow_payment', [ Api\IpnHandler::class, 'handle' ] );
	}

	protected function boot_extras(): void {
		// 顧客端取號繳費資訊（ATM 虛擬帳號 / 超商條碼 / ibon・FamiPort・iCash 代碼）。
		Frontend\CustomerPaymentInfo::init();
	}
}
