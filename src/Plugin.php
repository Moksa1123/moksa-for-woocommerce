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
		Settings\SettingsTab::register();
		Modules\Shipping\Module::boot();
		Modules\Address\TwAddress::init();
		if ( is_admin() ) {
			Modules\Shared\Admin\CardRenderers::boot();
			if ( $this->ai_hub_visible() ) {
				Modules\AiAssistant\Admin\Hub::boot();
			}
		}
		add_action( 'rest_api_init', [ Mcp\Server::class, 'register' ] );
		$this->modules->boot();
	}

	/**
	 * 「Moksa AI」子選單只在對應模組啟用時出現 —— 開關統一在「Moksa 電商工具 → 模組總覽」的卡片。
	 */
	private function ai_hub_visible(): bool {
		return $this->modules->is_enabled( 'ai_assistant' )
			|| $this->modules->is_enabled( 'customer_service' );
	}

	/**
	 * v1.5.1 之前有第二層總開關 moksafowo_ai_enabled，兩個模組的 boot() 都會再檢查它。
	 * 現在模組卡片是唯一開關，若舊站的總開關是關的，就把兩個模組一併關掉，維持原本的行為。
	 */
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
