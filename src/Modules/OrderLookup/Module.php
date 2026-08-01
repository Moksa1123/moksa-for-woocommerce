<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

use Moksafowo\Modules\AbstractModule;
use Moksafowo\Modules\OrderLookup\Index\Backfill;
use Moksafowo\Modules\OrderLookup\Index\Indexer;
use Moksafowo\Modules\OrderLookup\Index\Table;

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
		return __( 'Order number lookup — invoice, tracking and payment transaction numbers', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'tools';
	}

	public function name(): string {
		return __( 'Order number lookup', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( 'Find orders by invoice number, tracking number or payment transaction ID, from the order list and Ctrl+K', 'moksa-for-woocommerce' );
	}

	/**
	 * Abilities 註冊 —— 抽出來是因為 AI 助手也需要它們。
	 *
	 * 這 28 個 ability 是 AI 助手唯一的工具來源。若只在本模組啟用時註冊，
	 * 商家單獨開 AI 助手就會得到一個「沒有任何工具」的助手（外觀正常、實際不會動）。
	 * 所以 AiAssistant\Module::boot() 也會呼叫這裡，且做過一次就不重複。
	 */
	public static function register_abilities(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		add_action( 'wp_abilities_api_categories_init', [ Ability::class, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ Ability::class, 'register' ] );
		// gate_mcp_exposure 必須在 register 前掛，register 時才會 fire。
		add_filter( 'wp_register_ability_args', [ Ability::class, 'gate_mcp_exposure' ], 10, 2 );
		add_filter( 'woocommerce_mcp_include_ability', [ Ability::class, 'include_in_mcp' ], 10, 2 );
	}

	public function boot(): void {
		add_filter( 'woocommerce_order_table_search_query_meta_keys', [ SearchableKeys::class, 'add' ] );
		add_filter( 'woocommerce_shop_order_search_fields', [ SearchableKeys::class, 'add' ] );
		add_action( 'rest_api_init', [ Rest::class, 'register' ] );
		self::register_abilities();
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_command_palette' ] );

		Backfill::boot();
		Indexer::boot();
		if ( Table::is_enabled() ) {
			Table::maybe_install();
		}
		add_action( 'add_option_' . Table::ENABLED_OPTION, [ self::class, 'on_index_added' ], 10, 2 );
		add_action( 'update_option_' . Table::ENABLED_OPTION, [ self::class, 'on_index_toggled' ], 10, 2 );
		add_action( 'admin_init', [ self::class, 'maybe_handle_rebuild' ] );
		add_action( 'admin_notices', [ self::class, 'maybe_rebuild_notice' ] );
	}

	/**
	 * @param mixed $option option 名稱。
	 * @param mixed $value  新值。
	 */
	public static function on_index_added( $option, $value ): void {
		if ( 'yes' === $value ) {
			Table::maybe_install();
			Backfill::start();
		}
	}

	/**
	 * @param mixed $old_value 舊值。
	 * @param mixed $new_value 新值。
	 */
	public static function on_index_toggled( $old_value, $new_value ): void {
		if ( 'yes' === $new_value && 'yes' !== $old_value ) {
			Table::maybe_install();
			Backfill::start();
		}
	}

	/**
	 * 處理「重建索引」連結（manage_woocommerce + nonce）。
	 */
	public static function maybe_handle_rebuild(): void {
		if ( ! isset( $_GET['moksafowo_rebuild_order_index'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'moksafowo_rebuild_order_index' ) ) {
			return;
		}
		Table::maybe_install();
		Backfill::start();
		wp_safe_redirect(
			add_query_arg(
				'moksafowo_index_rebuilt',
				'1',
				remove_query_arg( [ 'moksafowo_rebuild_order_index', '_wpnonce' ] )
			)
		);
		exit;
	}

	public static function maybe_rebuild_notice(): void {
		if ( ! isset( $_GET['moksafowo_index_rebuilt'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 唯讀提示，無狀態變更。
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'Rebuilding the order number index has started in the background.', 'moksa-for-woocommerce' )
			. '</p></div>';
	}

	public static function enqueue_command_palette(): void {
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
