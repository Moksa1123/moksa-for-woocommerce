<?php
/**
 * Unit test bootstrap — 純 PHP 邏輯測試（不載入 WP）。
 *
 * 提供最小 WP function polyfill 讓 src/ 內 `__()`/`esc_html()` 等不爆。
 * 整合測試（觸碰 WC_Order / DB / hooks）走 server-side `wp eval-file scripts/test-*.php`。
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = '' ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sprintf' ) ) {
	// PHP 內建，不用 polyfill — 留為 placeholder 提醒不要在 src 用 WP-only function。
}

// 最小 shortcode_atts — AddFee shortcode unit test 需要
if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		$atts = (array) $atts;
		$out  = [];
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}
		return $out;
	}
}

require __DIR__ . '/../vendor/autoload.php';
