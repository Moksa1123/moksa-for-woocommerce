<?php

declare( strict_types=1 );

namespace Moksafowo;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	private ModuleRegistry $modules;

	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->modules = new ModuleRegistry();
	}

	public function __clone() {
		throw new \LogicException( 'Plugin is a singleton.' );
	}

	public function __wakeup(): void {
		throw new \LogicException( 'Plugin is a singleton.' );
	}

	public function modules(): ModuleRegistry {
		return $this->modules;
	}

	public static function version(): string {
		return MOKSAFOWO_VERSION;
	}

	public static function path( string $relative = '' ): string {
		return MOKSAFOWO_PLUGIN_DIR . ltrim( $relative, '/' );
	}

	public static function url( string $relative = '' ): string {
		return MOKSAFOWO_PLUGIN_URL . ltrim( $relative, '/' );
	}

	public static function file(): string {
		return MOKSAFOWO_PLUGIN_FILE;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! Compatibility\Requirements::met() ) {
			Compatibility\Requirements::register_admin_notice();
			return;
		}

		add_action( 'woocommerce_init', [ $this, 'on_woocommerce_init' ] );
		add_filter( 'plugin_action_links_' . MOKSAFOWO_PLUGIN_BASENAME, [ $this, 'plugin_action_links' ] );
		add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );
	}

	public function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=' . Settings\SettingsTab::TAB_ID ) ),
			esc_html__( 'Settings', 'moksa-for-woocommerce' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	public function plugin_row_meta( array $links, string $file ): array {
		if ( MOKSAFOWO_PLUGIN_BASENAME !== $file ) {
			return $links;
		}
		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noreferrer">%s</a>',
			'https://github.com/Moksa1123/moksa-for-woocommerce',
			esc_html__( 'GitHub', 'moksa-for-woocommerce' )
		);
		return $links;
	}

	public function on_woocommerce_init(): void {
		self::migrate_ai_master_switch();
		self::migrate_option_key_mismatches();
		Settings\SettingsTab::register();
		Modules\Shipping\Module::boot();
		Modules\Address\TwAddress::init();
		// 區塊信件編輯器的個人化標籤。無條件掛：它只註冊一個 filter，
		// 對應模組沒開時各標籤自然回空字串。
		Modules\Shared\Email\PersonalizationTags::init();
		if ( is_admin() ) {
			Modules\Shared\Admin\CardRenderers::boot();
			Modules\Shared\Admin\PaymentQuery::boot();
			// Hub 一律 boot —— 它自己決定要不要出現在側邊欄（見 Hub::menu()）。
			// 不 boot 的話頁面連路由都沒有，使用者點到舊連結會拿到誤導的權限錯誤。
			Modules\AiAssistant\Admin\Hub::boot();
		}
		add_action( 'rest_api_init', [ Mcp\Server::class, 'register' ] );
		$this->modules->boot();
	}

	/**
	 * v1.5.1 之前有第二層總開關 moksafowo_ai_enabled，兩個模組的 boot() 都會再檢查它。
	 * 現在模組卡片是唯一開關，若舊站的總開關是關的，就把兩個模組一併關掉，維持原本的行為。
	 */
	/**
	 * v1.8.9 修了兩組「設定頁存的 key」跟「程式讀的 key」對不上的問題：
	 * - 物流狀態開關：設定頁存 moksafowo_shipping_status_moksafowo_<x>_enabled，
	 *   Registrar 讀 moksafowo_shipping_status_moksa_<x>_enabled → 勾不勾都沒差。
	 *   商家若真的關過某個狀態，值在舊 key 上，搬過來才不會升級後又冒出來。
	 * - 郵遞區號自動帶入：設定從沒被 JS 讀過，一直都是開的。設定頁只要存過一次
	 *   就會留下 no（checkbox 預設沒勾），修好之後不能讓它突然關掉，統一設成 yes。
	 */
	private static function migrate_option_key_mismatches(): void {
		if ( 'done' === get_option( 'moksafowo_option_keys_migrated_189', '' ) ) {
			return;
		}
		foreach ( [ 'shipped', 'cvs_arrived', 'store_closed' ] as $x ) {
			$old = get_option( 'moksafowo_shipping_status_moksafowo_' . $x . '_enabled', null );
			if ( null !== $old ) {
				update_option( 'moksafowo_shipping_status_moksa_' . $x . '_enabled', $old, false );
				delete_option( 'moksafowo_shipping_status_moksafowo_' . $x . '_enabled' );
			}
		}
		update_option( 'moksafowo_tw_address_postcode_autofill', 'yes', false );
		update_option( 'moksafowo_option_keys_migrated_189', 'done', false );
	}

	private static function migrate_ai_master_switch(): void {
		if ( 'done' === get_option( 'moksafowo_ai_master_migrated', '' ) ) {
			return;
		}
		$old = get_option( 'moksafowo_ai_enabled', null );
		if ( null !== $old && 'yes' !== $old ) {
			update_option( 'moksafowo_ai_assistant_enabled', 'no', false );
			update_option( 'moksafowo_customer_service_enabled', 'no', false );
		}
		update_option( 'moksafowo_ai_master_migrated', 'done', false );
	}
}
