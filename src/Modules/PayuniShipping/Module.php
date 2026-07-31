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
		return __( 'PAYUNi shipping — 7-ELEVEN B2C and C2C ambient and frozen, plus T-Cat ambient, chilled and frozen (7 methods)', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'shipping';
	}

	public function name(): string {
		return __( 'PAYUNi shipping', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( '7-ELEVEN store pickup and T-Cat home delivery (ambient, chilled and frozen)', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( '7-ELEVEN bulk ambient', 'moksa-for-woocommerce' ),
			__( '7-ELEVEN bulk frozen', 'moksa-for-woocommerce' ),
			__( '7-ELEVEN store-to-store ambient', 'moksa-for-woocommerce' ),
			__( '7-ELEVEN store-to-store frozen', 'moksa-for-woocommerce' ),
			__( 'T-Cat ambient', 'moksa-for-woocommerce' ),
			__( 'T-Cat chilled', 'moksa-for-woocommerce' ),
			__( 'T-Cat frozen', 'moksa-for-woocommerce' ),
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
