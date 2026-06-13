<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shared\Invoice;

defined( 'ABSPATH' ) || exit;

final class CheckoutAssets {

	private static bool $registered = false;

	public static function register( string $default_love_code_option_key = '' ): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action(
			'wp_enqueue_scripts',
			static function () use ( $default_love_code_option_key ): void {
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

				// 預設捐贈碼 — JS 會自動帶進「愛心碼」欄位（type=donate 時顯示）
				$default_donate = '';
				if ( '' !== $default_love_code_option_key ) {
					$raw            = (string) get_option( $default_love_code_option_key, '' );
					$default_donate = (string) preg_replace( '/[^0-9]/', '', $raw );
					$default_donate = substr( $default_donate, 0, 7 );
				}
				wp_localize_script(
					'moksafowo-invoice-checkout-fields',
					'moksafowo_ecpay_invoice_defaults',  // legacy var name — JS 內部沿用，不換
					[ 'love_code' => $default_donate ]
				);
			}
		);
	}
}
