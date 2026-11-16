<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * 原子鎖：交給 WordPress 核心的 WP_Upgrader::create_lock()。
 *
 * 核心在 options 表用 INSERT IGNORE 佔一列（option_name 唯一），同時間只有一個請求搶得到，
 * 跟本外掛原本手寫的寫法相同；超過 $timeout 秒的殘留鎖會自動清掉再搶一次。
 * 未登入可打的節流計數要原子（先佔名額、成功退回），所以不能用 add_option 或 transient。
 *
 * 只在真的要上鎖時才載入 class-wp-upgrader.php（前台請求預設不會載入它）。
 */
final class Lock {

	/** 搶一次；搶不到回 false。 */
	public static function acquire( string $name, int $timeout ): bool {
		self::load();
		return (bool) \WP_Upgrader::create_lock( $name, max( 1, $timeout ) );
	}

	/** 搶不到就每 $wait_ms 毫秒再試，最多 $tries 次。 */
	public static function acquire_wait( string $name, int $timeout, int $tries, int $wait_ms ): bool {
		for ( $i = 0; $i < $tries; $i++ ) {
			if ( self::acquire( $name, $timeout ) ) {
				return true;
			}
			usleep( $wait_ms * 1000 );
		}
		return false;
	}

	public static function release( string $name ): void {
		self::load();
		\WP_Upgrader::release_lock( $name );
	}

	private static function load(): void {
		if ( ! class_exists( '\WP_Upgrader', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
	}

	private function __construct() {}
}
