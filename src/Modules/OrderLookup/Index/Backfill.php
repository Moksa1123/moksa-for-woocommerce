<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup\Index;

use Moksafowo\Modules\OrderLookup\SearchableKeys;

defined( 'ABSPATH' ) || exit;

/**
 * 既有訂單回填 —— 用 Action Scheduler 分批掃過所有訂單建索引，避免大站一次跑爆。
 * 啟用索引或按「重建索引」時觸發。
 */
final class Backfill {

	const HOOK            = 'moksafowo_order_lookup_backfill';
	const PROGRESS_OPTION = 'moksafowo_order_lookup_index_backfill';
	const BATCH           = 200;

	public static function boot(): void {
		add_action( self::HOOK, [ self::class, 'run_batch' ], 10, 1 );
	}

	/**
	 * 重建：清空索引 → 記錄總數 → 排第一批。
	 */
	public static function start(): void {
		Table::maybe_install();
		Table::truncate();

		$total = self::count_total();
		update_option(
			self::PROGRESS_OPTION,
			[
				'running' => true,
				'done'    => 0,
				'total'   => $total,
			],
			false
		);
		self::schedule( 1 );
	}

	/**
	 * @param mixed $page 批次頁碼。
	 */
	public static function run_batch( $page ): void {
		$page = max( 1, absint( $page ) );
		$ids  = wc_get_orders(
			[
				'type'    => 'shop_order',
				'status'  => array_keys( wc_get_order_statuses() ),
				'limit'   => self::BATCH,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			]
		);

		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				Table::replace_order( (int) $id, SearchableKeys::index_pairs( $order ) );
			}
		}

		$progress            = self::status();
		$progress['done']    = (int) $progress['done'] + count( $ids );
		$still_running       = count( $ids ) >= self::BATCH;
		$progress['running'] = $still_running;
		update_option( self::PROGRESS_OPTION, $progress, false );

		if ( $still_running ) {
			self::schedule( $page + 1 );
		}
	}

	private static function schedule( int $page ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::HOOK, [ $page ], 'mo-ectools' );
		} else {
			// 無 Action Scheduler（理論上 WC 一定有）—— 同步跑這批，避免完全不動。
			self::run_batch( $page );
		}
	}

	private static function count_total(): int {
		$result = wc_get_orders(
			[
				'type'     => 'shop_order',
				'status'   => array_keys( wc_get_order_statuses() ),
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			]
		);
		return isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * @return array{running:bool, done:int, total:int}
	 */
	public static function status(): array {
		$saved = get_option( self::PROGRESS_OPTION, [] );
		return [
			'running' => ! empty( $saved['running'] ),
			'done'    => isset( $saved['done'] ) ? (int) $saved['done'] : 0,
			'total'   => isset( $saved['total'] ) ? (int) $saved['total'] : 0,
		];
	}
}
