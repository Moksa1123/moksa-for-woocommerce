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
		return __( 'PayNow 立即富 — 信用卡 / 分期 / WebATM / ATM / 超商條碼 / ibon / FamiPort / iCash / 銀聯', 'mo-ectools' );
	}

	public function name(): string {
		return __( 'PayNow 立即富', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '信用卡 / ATM / 超商條碼 / ibon / FamiPort / iCash / 銀聯', 'mo-ectools' );
	}

	public function methods(): array {
		return [
			__( '信用卡', 'mo-ectools' ),
			__( '信用卡分期', 'mo-ectools' ),
			__( 'WebATM', 'mo-ectools' ),
			__( 'ATM 虛擬帳號', 'mo-ectools' ),
			__( '超商條碼繳費', 'mo-ectools' ),
			__( 'ibon 代碼繳費', 'mo-ectools' ),
			__( 'FamiPort 代碼繳費', 'mo-ectools' ),
			__( 'iCash 錢包', 'mo-ectools' ),
			__( '銀聯卡', 'mo-ectools' ),
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
