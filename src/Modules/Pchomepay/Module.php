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
		return __( 'PChomePay 支付連 — 信用卡 / 拍錢包 / ATM / 超商代碼 / 超商取貨', 'mo-ectools' );
	}

	public function name(): string {
		return __( 'PChomePay 支付連', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '信用卡 / 拍錢包 / ATM / 超商代碼 / 超商取貨付款', 'mo-ectools' );
	}

	public function methods(): array {
		return [
			__( '信用卡', 'mo-ectools' ),
			__( '拍錢包', 'mo-ectools' ),
			__( 'ATM 虛擬帳號', 'mo-ectools' ),
			__( '超商代碼繳費', 'mo-ectools' ),
			__( '7-11 取貨付款', 'mo-ectools' ),
			__( '全家取貨付款', 'mo-ectools' ),
			__( '萊爾富取貨付款', 'mo-ectools' ),
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
