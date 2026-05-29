<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Smilepay;

use MoksaWeb\Mowc\Modules\Shared\AbstractGatewayModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractGatewayModule {

	public function slug(): string {
		return 'smilepay';
	}

	public function label(): string {
		return __( 'SmilePay 速買配 — 信用卡 / 分期 / ATM / 超商條碼 / ibon / FamiPort / 銀聯', 'mo-ectools' );
	}

	public function name(): string {
		return __( 'SmilePay 速買配', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '信用卡 / 信用卡分期 / ATM / 四大超商條碼 / ibon / FamiPort / 銀聯', 'mo-ectools' );
	}

	public function methods(): array {
		return [
			__( '信用卡', 'mo-ectools' ),
			__( '信用卡分期', 'mo-ectools' ),
			__( 'ATM 虛擬帳號', 'mo-ectools' ),
			__( '四大超商條碼', 'mo-ectools' ),
			__( 'ibon 代碼繳費', 'mo-ectools' ),
			__( 'FamiPort 代碼繳費', 'mo-ectools' ),
			__( '銀聯線上刷卡', 'mo-ectools' ),
		];
	}

	public function settings_section(): string {
		return 'smilepay-payment';
	}

	public static function gateway_map(): array {
		return [
			Gateways\Credit::GATEWAY_ID            => Gateways\Credit::class,
			Gateways\CreditInstallment::GATEWAY_ID => Gateways\CreditInstallment::class,
			Gateways\Atm::GATEWAY_ID               => Gateways\Atm::class,
			Gateways\Barcode::GATEWAY_ID           => Gateways\Barcode::class,
			Gateways\Ibon::GATEWAY_ID              => Gateways\Ibon::class,
			Gateways\FamiPort::GATEWAY_ID          => Gateways\FamiPort::class,
			Gateways\Unionpay::GATEWAY_ID          => Gateways\Unionpay::class,
		];
	}

	protected static function blocks_method_class(): string {
		return Blocks\SmilepayBlocksMethod::class;
	}

	protected function register_webhooks(): void {
		add_action( 'woocommerce_api_roturl', [ Api\IpnHandler::class, 'handle_roturl' ] );
		add_action( 'woocommerce_api_credit_roturl', [ Api\IpnHandler::class, 'handle_credit_roturl' ] );
	}

	protected function boot_extras(): void {
		// 顧客端取號繳費資訊（ATM/ibon/FamiPort/條碼）。
		Frontend\CustomerPaymentInfo::init();
	}
}
