<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Setup;

defined( 'ABSPATH' ) || exit;

final class GatewayAllowlistMigrator {

	public static function seed_if_unseeded( string $provider, array $gateway_ids ): void {
		$flag_key = 'moksafowo_' . $provider . '_methods_seeded';
		if ( 'yes' === get_option( $flag_key ) ) {
			return;
		}

		$opt_key   = 'moksafowo_' . $provider . '_enabled_methods';
		$allowlist = get_option( $opt_key, null );

		// 已明確設過非空 allowlist → 商家已自選，標記 seeded 不動。autoload=false：seeded flag 非熱路徑用。
		if ( is_array( $allowlist ) && ! empty( $allowlist ) ) {
			update_option( $flag_key, 'yes', false );
			return;
		}

		$seed = [];
		foreach ( $gateway_ids as $id ) {
			$settings = get_option( 'woocommerce_' . $id . '_settings', [] );
			if ( is_array( $settings ) && isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] ) {
				$seed[] = (string) $id;
			}
		}
		update_option( $opt_key, $seed, false );
		update_option( $flag_key, 'yes', false );
	}
}
