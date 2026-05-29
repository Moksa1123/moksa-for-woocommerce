<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc;

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
		return MOWC_VERSION;
	}

	public static function path( string $relative = '' ): string {
		return MOWC_PLUGIN_DIR . ltrim( $relative, '/' );
	}

	public static function url( string $relative = '' ): string {
		return MOWC_PLUGIN_URL . ltrim( $relative, '/' );
	}

	public static function file(): string {
		return MOWC_PLUGIN_FILE;
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
		add_filter( 'plugin_action_links_' . MOWC_PLUGIN_BASENAME, [ $this, 'plugin_action_links' ] );
		add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );
	}

	public function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=' . Settings\SettingsTab::TAB_ID ) ),
			esc_html__( '設定', 'mo-ectools' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	public function plugin_row_meta( array $links, string $file ): array {
		if ( MOWC_PLUGIN_BASENAME !== $file ) {
			return $links;
		}
		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noreferrer">%s</a>',
			'https://github.com/Moksa1123/moksa-for-woocommerce',
			esc_html__( 'GitHub', 'mo-ectools' )
		);
		return $links;
	}

	public function on_woocommerce_init(): void {
		Settings\SettingsTab::register();
		Modules\Shipping\Module::boot();
		Modules\Address\TwAddress::init();
		if ( is_admin() ) {
			Modules\Shared\Admin\CardRenderers::boot();
		}
		$this->modules->boot();
	}
}
