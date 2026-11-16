<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\CustomerService;

use Moksafowo\Modules\Shared\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * 原子計數：先讀再寫的 transient 在並發請求下會超出上限，這裡用核心的原子鎖（Shared\Lock）包住讀寫。
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

	/** 搶不到就每 25ms 再試，最多 20 次；超過 10 秒的鎖視為殘留。 */
	private static function lock( string $key ): bool {
		return Lock::acquire_wait( self::lock_name( $key ), 10, 20, 25 );
	}

	private static function unlock( string $key ): void {
		Lock::release( self::lock_name( $key ) );
	}

	private static function lock_name( string $key ): string {
		return 'moksafowo_cs_rl_lock_' . md5( $key );
	}
}
