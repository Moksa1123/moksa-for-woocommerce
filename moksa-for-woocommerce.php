<?php
/**
 * Plugin Name:        Moksa for WooCommerce
 * Plugin URI:         https://github.com/Moksa1123/moksa-for-woocommerce
 * Description:        Taiwan payment, shipping and e-invoice toolkit for WooCommerce. Enable the provider modules you need (ECPay, NewebPay, PAYUNi, SmilePay, LINE Pay, PayNow, PChomePay, TapPay, Shopline Payments, ezPay, AMEGO). HPOS-ready, Block Checkout-ready.
 * Version:            1.6.2
 * Requires at least:  7.0
 * Tested up to:       7.0
 * Requires PHP:       8.2
 * Requires Plugins:   woocommerce
 * WC requires at least: 9.9
 * WC tested up to:    11.0
 * Author:             MoksaWeb
 * Author URI:         https://moksaweb.com/
 * License:            GPLv3
 * License URI:        https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:        moksa-for-woocommerce
 * Domain Path:        /languages
 *
 * @package Moksafowo
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/* Constants */
const MOKSAFOWO_VERSION    = '1.6.2';
const MOKSAFOWO_MIN_PHP    = '8.2';
const MOKSAFOWO_MIN_WP     = '7.0';
const MOKSAFOWO_MIN_WC     = '9.9';
const MOKSAFOWO_TEXTDOMAIN = 'moksa-for-woocommerce';

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
			echo esc_html__( 'MO ECtools: composer autoload missing. Run `composer install` in the plugin directory.', 'moksa-for-woocommerce' );
			echo '</p></div>';
		}
	);
	return;
}
require_once $mo_autoload;

/*
 * Moksa AI 共用層 —— 每個 Moksa 外掛都 bundle 一份，全站只有版本最高的那份會執行
 * （見 lib/moksa-ai/moksa-ai.php 的選舉）。這個檔案不宣告任何類別或常數，
 * 所以多個外掛同時載入也不會撞。
 */
require_once MOKSAFOWO_PLUGIN_DIR . 'lib/moksa-ai/moksa-ai.php';

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
		\Moksafowo\Plugin::instance()->boot();
	},
	5
);
