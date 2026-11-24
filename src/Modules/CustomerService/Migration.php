<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\CustomerService;

use Moksafowo\Logging\Logger;
use Moksafowo\Modules\Shared\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * 客服資料從舊自有表搬到 CPT + 留言（docs/B批-資料庫查詢改寫設計.md §7.1）。
 *
 * - 分批：Action Scheduler，每批 BATCH 個對話，依舊 id 遞增。
 * - 續跑：游標 option 記最後搬完的舊 id；中斷後下一批從游標接著做。
 * - 冪等：對話用 post_name = cs-legacy-{舊id} 找，有就不重建；訊息只補舊表比新留言多的部分
 *   （訊息只會新增，依 id 排序比數量），重跑不會重複。
 * - 回滾：舊表一筆都不刪。
 * - 收尾：全部搬完先切 cpt，再補一次漏，5 分鐘後再補一次（切換瞬間仍在寫舊表的請求）。
 */
final class Migration {

	public const HOOK         = 'moksafowo_cs_migrate_batch';
	public const CATCH_UP     = 'moksafowo_cs_migrate_catch_up';
	public const CURSOR       = 'moksafowo_cs_migrate_cursor';
	private const LOCK        = 'moksafowo_cs_migrate';
	private const GROUP       = 'moksa-for-woocommerce';
	private const BATCH       = 100;
	private const CATCH_BATCH = 200;

	public static function boot(): void {
		add_action( self::HOOK, [ self::class, 'run_batch' ] );
		add_action( self::CATCH_UP, [ self::class, 'catch_up' ] );
		// 模組在 woocommerce_init 開機，那時 Action Scheduler 的資料儲存還沒初始化，排程會失敗。
		\Moksafowo\Modules\Shared\Scheduler::when_ready( [ self::class, 'maybe_start' ] );
	}

	/** 舊站開始搬；停在「搬遷中」卻沒有排程（排程曾失敗、被清掉）時補排一次。 */
	public static function maybe_start(): void {
		$mode = Storage::mode();
		if ( Storage::LEGACY === $mode ) {
			self::start();
		} elseif ( Storage::MIGRATING === $mode ) {
			self::schedule( self::HOOK );
		}
	}

	public static function start(): void {
		Storage::set( Storage::MIGRATING );
		// 讀成不存在會把游標歸零、從頭再搬一次。
		if ( false === \Moksafowo\Support\FreshOption::get( self::CURSOR, false ) ) {
			update_option( self::CURSOR, 0, false );
		}
		self::schedule( self::HOOK );
	}

	public static function run_batch(): void {
		if ( Storage::MIGRATING !== Storage::mode() ) {
			return;
		}
		// 同一時間只跑一份：兩個排程同時搬會建出重複的對話與訊息。
		if ( ! Lock::acquire( self::LOCK, 10 * MINUTE_IN_SECONDS ) ) {
			return;
		}
		try {
			self::batch();
		} finally {
			Lock::release( self::LOCK );
		}
	}

	private static function batch(): void {
		$cursor = (int) get_option( self::CURSOR, 0 );
		$rows   = LegacyThreads::threads_after( $cursor, self::BATCH );
		foreach ( $rows as $row ) {
			self::copy_thread( $row );
			$cursor = (int) $row['id'];
			update_option( self::CURSOR, $cursor, false );
		}
		if ( count( $rows ) === self::BATCH ) {
			// 這一批本身還是 in-progress，不能用 as_has_scheduled_action 去重，直接排下一批。
			self::schedule( self::HOOK, false );
			return;
		}
		// 全部搬完：先切換，之後的新訊息就寫進 CPT；再補切換前後漏掉的訊息。
		Storage::set( Storage::CPT );
		update_option( self::CURSOR, 0, false );
		self::copy_all();
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::CATCH_UP, [], self::GROUP );
		}
		Logger::info( 'customer-service', 'migration to WordPress posts finished', [ 'threads' => $cursor ] );
	}

	/** 把舊表比 CPT 多出來的訊息補上；也補沒搬到的對話。分批跑，避免一次撈太多。 */
	public static function catch_up(): void {
		if ( ! Lock::acquire( self::LOCK, 10 * MINUTE_IN_SECONDS ) ) {
			// 另一份搬遷或補漏正在跑；5 分鐘後的那一次補漏會再檢查。
			return;
		}
		try {
			self::copy_all();
		} finally {
			Lock::release( self::LOCK );
		}
	}

	private static function copy_all(): void {
		$cursor = 0;
		do {
			$rows = LegacyThreads::threads_after( $cursor, self::CATCH_BATCH );
			foreach ( $rows as $row ) {
				self::copy_thread( $row );
				$cursor = (int) $row['id'];
			}
			$full = self::CATCH_BATCH === count( $rows );
		} while ( $full );
	}

	/**
	 * 搬一個舊對話（冪等）。
	 *
	 * @param array<string,mixed> $row 舊表的一列。
	 */
	public static function copy_thread( array $row ): int {
		$legacy_id = (int) $row['id'];
		$post      = ThreadPosts::find_legacy( $legacy_id );
		$created   = ! $post;
		$post_id   = $post ? (int) $post->ID : ThreadPosts::insert_thread(
			(int) $row['order_id'],
			(string) $row['customer_ref'],
			(string) $row['status'],
			(int) $row['unread_staff'],
			(string) $row['created_at'],
			ThreadPosts::LEGACY_PREFIX . $legacy_id
		);
		if ( $post_id <= 0 ) {
			Logger::warning( 'customer-service', 'could not copy a legacy thread', [ 'legacy_id' => $legacy_id ] );
			return 0;
		}
		$have     = ThreadPosts::count_messages( $post_id );
		$messages = LegacyThreads::get_messages( $legacy_id );
		$appended = array_slice( $messages, $have );
		foreach ( $appended as $m ) {
			ThreadPosts::insert_message( $post_id, (string) $m['sender'], (string) $m['body'], (string) $m['created_at'] );
		}
		if ( $created || Storage::MIGRATING === Storage::mode() ) {
			// 搬遷期間舊表仍是正本：狀態、未讀、更新時間都照舊表（更新時間不能變成搬遷當下，收件匣靠它排序）。
			ThreadPosts::sync_state( $post_id, (string) $row['status'], (int) $row['unread_staff'], (string) $row['updated_at'] );
		} elseif ( [] !== $appended ) {
			// 切換之後新資料才是正本：不拿舊表的狀態蓋掉，只依補上的最後一則訊息調整。
			$last = end( $appended );
			if ( 'customer' === (string) $last['sender'] ) {
				ThreadPosts::sync_state( $post_id, 'open', 1, (string) $last['created_at'] );
			} else {
				ThreadPosts::sync_state( $post_id, 'answered', null, (string) $last['created_at'] );
			}
		}
		return $post_id;
	}

	/**
	 * 搬遷進度（設定頁顯示用）。
	 *
	 * @return array{mode:string, cursor:int, total:int}
	 */
	public static function status(): array {
		return [
			'mode'   => Storage::mode(),
			'cursor' => (int) get_option( self::CURSOR, 0 ),
			'total'  => Schema::exists() ? LegacyThreads::count_all() : 0,
		];
	}

	private static function schedule( string $hook, bool $dedupe = true ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( $dedupe && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, [], self::GROUP ) ) {
			return;
		}
		as_enqueue_async_action( $hook, [], self::GROUP );
	}

	private function __construct() {}
}
