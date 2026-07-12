<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Invoice;

defined( 'ABSPATH' ) || exit;

final class CheckoutAssets {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action(
			'wp_enqueue_scripts',
			static function (): void {
				if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
					return;
				}
				$path    = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Shared/Invoice/checkout-fields.js';
				$version = file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOWO_VERSION;
				wp_enqueue_script(
					'moksafowo-invoice-checkout-fields',
					MOKSAFOWO_PLUGIN_URL . 'src/Modules/Shared/Invoice/checkout-fields.js',
					[],
					$version,
					true
				);
			}
		);
	}
}
