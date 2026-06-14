<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

use MoksaWeb\Mowc\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單查號搜尋 — 讓 WC 訂單搜尋框、命令面板、AI 都能用台灣特有號碼
 * （發票號 / 物流單號 / 金流交易序號）找訂單。
 *
 * P1 訂單列表搜尋 + P2 Tier2/命中標示 + P3 命令面板 + Abilities API。
 * 完全解耦：只搜「已啟用模組」的號碼，不碰其他模組內部。
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'order_lookup';
	}

	public function label(): string {
		return __( '訂單查號搜尋 — 發票號 / 物流單號 / 金流交易序號', 'mo-ectools' );
	}

	public function category(): string {
		return 'tools';
	}

	public function name(): string {
		return __( '訂單查號搜尋', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '用發票號 / 物流單號 / 金流交易序號找訂單（訂單列表 + Ctrl+K）', 'mo-ectools' );
	}

	public function boot(): void {
		// P1/P2 — 訂單搜尋納入號碼 meta（HPOS + CPT）。
		add_filter( 'woocommerce_order_table_search_query_meta_keys', [ SearchableKeys::class, 'add' ] );
		add_filter( 'woocommerce_shop_order_search_fields', [ SearchableKeys::class, 'add' ] );

		// P3 — REST endpoint（命令面板用）。
		add_action( 'rest_api_init', [ Rest::class, 'register' ] );

		// P3 — Abilities API（命令面板 / AI 共用）。需 WP 6.9+。
		add_action( 'wp_abilities_api_categories_init', [ Ability::class, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ Ability::class, 'register' ] );
		add_filter( 'woocommerce_mcp_include_ability', [ Ability::class, 'include_in_mcp' ], 10, 2 );

		// P3 — 命令面板 loader（admin 全域，命令面板本身就是全域功能）。
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_command_palette' ] );
	}

	public static function enqueue_command_palette(): void {
		// 命令面板是全 admin 功能，無法用單一螢幕閘；腳本極小且僅註冊 loader。
		if ( ! wp_script_is( 'wp-commands', 'registered' ) ) {
			return;
		}
		$rel  = 'src/Modules/OrderLookup/assets/js/command-palette.js';
		$path = MOKSAFOWO_PLUGIN_DIR . $rel;
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOWO_VERSION;
		wp_enqueue_script(
			'moksafowo-order-lookup-command',
			MOKSAFOWO_PLUGIN_URL . $rel,
			[ 'wp-commands', 'wp-element', 'wp-api-fetch', 'wp-data' ],
			$ver,
			true
		);
	}
}
