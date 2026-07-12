<?php

declare( strict_types=1 );

namespace Moksafowo\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public const TAB_ID = 'mo-ectools';

	public static function register(): void {
		add_filter( 'woocommerce_get_settings_pages', [ self::class, 'add_page' ] );
	}

	public static function add_page( array $pages ): array {
		if ( ! class_exists( '\WC_Settings_Page' ) ) {
			return $pages;
		}
		require_once __DIR__ . '/SettingsPage.php';
		$pages[] = new SettingsPage();
		return $pages;
	}
}
