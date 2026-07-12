<?php
/**
 * Plugin Name:        Moksa for WooCommerce
 * Plugin URI:         https://github.com/Moksa1123/moksa-for-woocommerce
 * Description:        Taiwan payment, shipping and e-invoice toolkit for WooCommerce. Enable the provider modules you need (ECPay, NewebPay, PAYUNi, SmilePay, LINE Pay, PayNow, PChomePay, TapPay, Shopline Payments, ezPay, AMEGO). HPOS-ready, Block Checkout-ready.
 * Version:            1.4.5
 * Requires at least:  7.0
 * Tested up to:       7.0
 * Requires PHP:       8.2
 * Requires Plugins:   woocommerce
 * WC requires at least: 9.9
 * WC tested up to:    10.7
 * Author:             MoksaWeb
 * Author URI:         https://moksaweb.com/
 * License:            GPLv3
 * License URI:        https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:        mo-ectools
 * Domain Path:        /languages
 *
 * @package MoksaWeb\Mowc
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/* Constants */
const MOKSAFOWO_VERSION    = '1.4.5';
const MOKSAFOWO_MIN_PHP    = '8.2';
const MOKSAFOWO_MIN_WP     = '7.0';
const MOKSAFOWO_MIN_WC     = '9.9';
const MOKSAFOWO_TEXTDOMAIN = 'mo-ectools';

define( 'MOKSAFOWO_PLUGIN_FILE', __FILE__ );
define( 'MOKSAFOWO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOKSAFOWO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MOKSAFOWO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
Composer autoload */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_autoload = MOKSAFOWO_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! file_exists( $mo_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'MO ECtools: composer autoload missing. Run `composer install` in the plugin directory.', 'mo-ectools' );
			echo '</p></div>';
		}
	);
	return;
}
require_once $mo_autoload;

/* HPOS + Block Checkout compatibility — must run before woocommerce_init */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				MOKSAFOWO_PLUGIN_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				MOKSAFOWO_PLUGIN_FILE,
				true
			);
		}
	}
);

/* Boot */
add_action(
	'plugins_loaded',
	static function (): void {
		\MoksaWeb\Mowc\Plugin::instance()->boot();
	},
	5
);
