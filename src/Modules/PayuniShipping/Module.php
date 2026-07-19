<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping;

use Moksafowo\Crypto\Vault;
use Moksafowo\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'payuni_shipping';
	}

	public function label(): string {
		return __( 'PAYUNi 物流 — 7-11 B2C/C2C 常溫/冷凍 + 黑貓常溫/冷凍/冷藏（共 7 種）', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'shipping';
	}

	public function name(): string {
		return __( 'PAYUNi 物流', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( '7-11 超商取貨 + 黑貓宅配（常溫 / 冷藏 / 冷凍）', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( '7-11 大宗常溫', 'moksa-for-woocommerce' ),
			__( '7-11 大宗冷凍', 'moksa-for-woocommerce' ),
			__( '7-11 店到店常溫', 'moksa-for-woocommerce' ),
			__( '7-11 店到店冷凍', 'moksa-for-woocommerce' ),
			__( '黑貓常溫', 'moksa-for-woocommerce' ),
			__( '黑貓冷藏', 'moksa-for-woocommerce' ),
			__( '黑貓冷凍', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'moksafowo-payuni-shipping';
	}

	public function boot(): void {
		// 共用 PAYUNi Payment hashkey/iv；Payment 未啟用時補 wrap，避免讀到 MOWPv1 密文當金鑰
		foreach ( [
			'moksafowo_payuni_payment_hashkey',
			'moksafowo_payuni_payment_hashkey_test',
			'moksafowo_payuni_payment_hashiv',
			'moksafowo_payuni_payment_hashiv_test',
		] as $opt ) {
			Vault::wrap_option( $opt );
		}

		PayuniShipping::init();
	}
}
