<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\CustomerService;

defined( 'ABSPATH' ) || exit;

/**
 * 原子計數：先讀再寫的 transient 在並發請求下會超出上限，這裡用資料庫鎖包住讀寫。
 * 拿不到鎖時一律當作已達上限。
 */
final class RateLimit {

	public static function take( string $key, int $limit, int $ttl ): bool {
		if ( ! self::lock( $key ) ) {
			return false;
		}
		$count = (int) get_transient( $key );
		$ok    = $count < $limit;
		if ( $ok ) {
			set_transient( $key, $count + 1, $ttl );
		}
		self::unlock( $key );
		return $ok;
	}

	public static function release( string $key, int $ttl ): void {
		if ( ! self::lock( $key ) ) {
			return;
		}
		$count = (int) get_transient( $key );
		if ( $count > 0 ) {
			set_transient( $key, $count - 1, $ttl );
		}
		self::unlock( $key );
	}

	private static function lock( string $key ): bool {
		global $wpdb;
		$name = self::lock_name( $key );
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock row; must bypass the options cache.
			$got = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $name, (string) time(), 'off' ) );
			if ( 1 === $got ) {
				return true;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the lock row written above.
			$since = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
			if ( $since > 0 && time() - $since > 10 ) {
				self::unlock( $key );
				continue;
			}
			usleep( 25000 );
		}
		return false;
	}

	private static function unlock( string $key ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the lock row written by lock().
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::lock_name( $key ) ) );
	}

	private static function lock_name( string $key ): string {
		return 'moksafowo_cs_rl_lock_' . md5( $key );
	}
}
