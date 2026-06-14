<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 號碼 → 訂單查詢核心。REST endpoint 與 Abilities API 共用同一份。
 *
 * 重用已註冊的搜尋 filter：wc_get_orders 的 's' 在 HPOS 下會把
 * SearchableKeys 加入的號碼 meta 一併納入（單一查詢），不另組 meta_query。
 */
final class OrderNumberLookup {

	/**
	 * @param string $term  搜尋字串（發票 / 物流 / 金流號碼）。
	 * @param int    $limit 最多回傳幾筆。
	 * @return array<int, array{id:int, number:string, name:string, status:string, matched:string, edit_url:string}>
	 */
	public static function resolve( string $term, int $limit = 10 ): array {
		$term = trim( $term );
		if ( mb_strlen( $term ) < 3 ) {
			return [];
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
			$name = trim( $order->get_formatted_billing_full_name() );
			$out[] = [
				'id'       => (int) $id,
				'number'   => (string) $order->get_order_number(),
				'name'     => '' !== $name ? $name : __( '（無姓名）', 'mo-ectools' ),
				'status'   => wc_get_order_status_name( $order->get_status() ),
				'matched'  => SearchableKeys::matched_label( $order, $term ),
				'edit_url' => $order->get_edit_order_url(),
			];
		}
		return $out;
	}
}
