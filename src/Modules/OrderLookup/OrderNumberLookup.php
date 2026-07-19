<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

use Moksafowo\Modules\OrderLookup\Index\Table;

defined( 'ABSPATH' ) || exit;

/**
 * 號碼 → 訂單查詢核心。REST endpoint 與 Abilities API 共用同一份。
 *
 * 索引就緒時走加速索引表（單表 indexed lookup）；否則 fallback 回 wc_get_orders
 * 的 's'（在 HPOS 下會把 SearchableKeys 加入的號碼 meta 一併納入）。
 */
final class OrderNumberLookup {

	/**
	 * @param string $term  搜尋字串（訂單編號 / 發票 / 物流 / 金流號碼）。
	 * @param int    $limit 最多回傳幾筆。
	 * @return array<int, array{id:int, number:string, name:string, status:string, total:string, matched:string, edit_url:string}>
	 */
	public static function resolve( string $term, int $limit = 10 ): array {
		$term = trim( $term );
		if ( mb_strlen( $term ) < 3 ) {
			return [];
		}

		// 純數字先精確比對訂單編號，命中即回，不混入模糊搜尋雜訊。
		if ( ctype_digit( $term ) ) {
			$order = wc_get_order( (int) $term );
			if ( $order && 'shop_order' === $order->get_type() ) {
				return [ self::format( $order, __( '訂單編號', 'moksa-for-woocommerce' ) ) ];
			}
		}

		if ( Table::is_ready() ) {
			return self::resolve_via_index( $term, $limit );
		}

		$ids = wc_get_orders(
			[
				's'      => $term,
				'return' => 'ids',
				'limit'  => max( 1, $limit ),
			]
		);

		$out = [];
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$out[] = self::format( $order, SearchableKeys::matched_label( $order, $term ) );
		}
		return $out;
	}

	/**
	 * 索引表查詢路徑。
	 *
	 * @param string $term  搜尋字串。
	 * @param int    $limit 最多回傳幾筆。
	 * @return array<int, array{id:int, number:string, name:string, status:string, total:string, matched:string, edit_url:string}>
	 */
	private static function resolve_via_index( string $term, int $limit ): array {
		$fields = SearchableKeys::query_fields();
		if ( empty( $fields ) ) {
			return [];
		}
		$ids = Table::search( $fields, $term, max( 1, $limit ) );
		$out = [];
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$out[] = self::format( $order, SearchableKeys::index_matched_label( $order, $term ) );
		}
		return $out;
	}

	/**
	 * @param \WC_Order $order   訂單。
	 * @param string    $matched 命中的欄位標籤。
	 * @return array{id:int, number:string, name:string, status:string, total:string, matched:string, edit_url:string}
	 */
	private static function format( \WC_Order $order, string $matched ): array {
		$name = trim( $order->get_formatted_billing_full_name() );
		return [
			'id'       => (int) $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'name'     => '' !== $name ? $name : __( '（無姓名）', 'moksa-for-woocommerce' ),
			'status'   => wc_get_order_status_name( $order->get_status() ),
			'total'    => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
			'matched'  => $matched,
			'edit_url' => $order->get_edit_order_url(),
		];
	}
}
