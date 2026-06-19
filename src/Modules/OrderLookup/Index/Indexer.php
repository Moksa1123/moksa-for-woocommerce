<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup\Index;

use MoksaWeb\Mowc\Modules\OrderLookup\SearchableKeys;

defined( 'ABSPATH' ) || exit;

/**
 * 索引維護 —— 訂單存檔時即時更新該訂單的號碼索引列，刪除時清掉。
 * 只在索引啟用時運作（停用零負擔；重新啟用由 Backfill 補回）。
 */
final class Indexer {

	/**
	 * 掛 HPOS + CPT 都會 fire 的存檔 / 刪除 hook。
	 */
	public static function boot(): void {
		add_action( 'woocommerce_after_order_object_save', [ self::class, 'on_save' ], 20, 1 );
		add_action( 'woocommerce_delete_order', [ self::class, 'on_delete' ], 20, 1 );
		add_action( 'woocommerce_trash_order', [ self::class, 'on_delete' ], 20, 1 );
	}

	/**
	 * @param mixed $order WC_Order（woocommerce_after_order_object_save 第一參數）。
	 */
	public static function on_save( $order ): void {
		if ( ! Table::is_enabled() || ! $order instanceof \WC_Order ) {
			return;
		}
		if ( 'shop_order' !== $order->get_type() ) {
			return;
		}
		self::reindex( $order );
	}

	/**
	 * @param mixed $order_id 訂單 ID。
	 */
	public static function on_delete( $order_id ): void {
		if ( ! Table::is_enabled() ) {
			return;
		}
		$id = absint( $order_id );
		if ( $id > 0 ) {
			Table::delete_order( $id );
		}
	}

	/**
	 * 重建單一訂單的索引列（先刪後插）。
	 */
	public static function reindex( \WC_Order $order ): void {
		$pairs = SearchableKeys::index_pairs( $order );
		Table::replace_order( (int) $order->get_id(), $pairs );
	}
}
