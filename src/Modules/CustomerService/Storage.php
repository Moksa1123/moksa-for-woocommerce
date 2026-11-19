<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\CustomerService;

defined( 'ABSPATH' ) || exit;

/**
 * 客服資料目前存在哪裡：legacy（舊自有表）→ migrating（背景搬遷中，仍讀寫舊表）→ cpt。
 *
 * 新站（舊表不存在或沒有對話）直接用 cpt，不再建舊表。
 */
final class Storage {

	public const OPTION    = 'moksafowo_cs_storage';
	public const LEGACY    = 'legacy';
	public const MIGRATING = 'migrating';
	public const CPT       = 'cpt';

	public static function mode(): string {
		// 讀成不存在會重算：舊表還留著（給回滾用）就算回 legacy，已搬完的站會被切回舊表。
		$mode = (string) \Moksafowo\Support\FreshOption::get( self::OPTION, '' );
		if ( in_array( $mode, [ self::LEGACY, self::MIGRATING, self::CPT ], true ) ) {
			return $mode;
		}
		// 第一次判斷：舊表有對話就要搬，沒有就直接用新的。
		$mode = ( Schema::exists() && LegacyThreads::count_all() > 0 ) ? self::LEGACY : self::CPT;
		update_option( self::OPTION, $mode, false );
		return $mode;
	}

	public static function uses_cpt(): bool {
		return self::CPT === self::mode();
	}

	public static function set( string $mode ): void {
		update_option( self::OPTION, $mode, false );
	}

	private function __construct() {}
}
