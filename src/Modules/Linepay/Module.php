<?php


declare( strict_types=1 );

namespace Moksafowo\Modules\Linepay;

use Moksafowo\Crypto\Vault;
use Moksafowo\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'linepay';
	}

	public function label(): string {
		return __( 'LINE Pay 台灣 — 信用卡付款', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'payment';
	}

	public function name(): string {
		return __( 'LINE Pay 台灣', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( '信用卡付款', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [ __( '信用卡', 'moksa-for-woocommerce' ) ];
	}

	public function settings_section(): string {
		return 'linepay';
	}

	public function boot(): void {
		// Patch 7: at-rest encryption for channel secrets.
		Vault::wrap_option( 'moksafowo_linepay_channel_secret' );
		Vault::wrap_option( 'moksafowo_linepay_sandbox_channel_secret' );

		LinePay::init();

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ): void {
				if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
					return;
				}
				$registry->register( new Blocks\LinepayBlocksMethod() );
			}
		);
	}
}
