<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Compatibility;

defined( 'ABSPATH' ) || exit;

final class Requirements {

	private static array $messages = [];

	public static function met(): bool {
		self::$messages = [];

		if ( version_compare( PHP_VERSION, MOKSAFOWO_MIN_PHP, '<' ) ) {
			self::$messages[] = sprintf(
				/* translators: 1: required PHP, 2: current PHP. */
				__( 'Moksa requires PHP %1$s or higher. You are running %2$s.', 'mo-ectools' ),
				MOKSAFOWO_MIN_PHP,
				PHP_VERSION
			);
		}

		global $wp_version;
		if ( version_compare( (string) $wp_version, MOKSAFOWO_MIN_WP, '<' ) ) {
			self::$messages[] = sprintf(
				/* translators: 1: required WP, 2: current WP. */
				__( 'Moksa requires WordPress %1$s or higher. You are running %2$s.', 'mo-ectools' ),
				MOKSAFOWO_MIN_WP,
				(string) $wp_version
			);
		}

		if ( ! self::woocommerce_active() ) {
			self::$messages[] = __( 'Moksa requires WooCommerce to be active.', 'mo-ectools' );
		} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, MOKSAFOWO_MIN_WC, '<' ) ) {
			self::$messages[] = sprintf(
				/* translators: 1: required WC, 2: current WC. */
				__( 'Moksa requires WooCommerce %1$s or higher. You are running %2$s.', 'mo-ectools' ),
				MOKSAFOWO_MIN_WC,
				WC_VERSION
			);
		}

		return self::$messages === [];
	}

	public static function register_admin_notice(): void {
		$messages = self::$messages;
		if ( $messages === [] ) {
			return;
		}
		add_action(
			'admin_notices',
			static function () use ( $messages ): void {
				echo '<div class="notice notice-error"><p><strong>Moksa</strong></p><ul style="list-style:disc;margin-left:1.5em;">';
				foreach ( $messages as $msg ) {
					echo '<li>' . esc_html( $msg ) . '</li>';
				}
				echo '</ul></div>';
			}
		);
	}

	private static function woocommerce_active(): bool {
		if ( class_exists( \WooCommerce::class ) ) {
			return true;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core filter name; reading actual active-plugins list to check if WooCommerce is active.
		$active = (array) apply_filters( 'active_plugins', get_option( 'active_plugins', [] ) );
		return in_array( 'woocommerce/woocommerce.php', $active, true );
	}
}
