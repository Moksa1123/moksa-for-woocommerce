<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單數量 / 狀態查詢核心。給 ability `mo-ectools/query-orders` 用。
 *
 * 不給 status → 回各狀態的筆數分布;給 status → 額外回該狀態最近 10 筆樣本。
 */
final class QueryOrders {

	/**
	 * @param mixed $input { status?: string }。
	 * @return array<string, mixed>
	 */
	public static function run( $input ): array {
		$status = is_array( $input ) && ! empty( $input['status'] )
			? str_replace( 'wc-', '', sanitize_key( (string) $input['status'] ) )
			: '';
		// all / any = 不指定狀態,只回各狀態分布。
		if ( in_array( $status, array( 'all', 'any', 'breakdown' ), true ) ) {
			$status = '';
		}

		$breakdown = array();
		$total     = 0;
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$bare  = str_replace( 'wc-', '', $slug );
			$count = (int) wc_orders_count( $bare );
			if ( $count > 0 ) {
				$breakdown[] = array(
					'status' => $label,
					'slug'   => $bare,
					'count'  => $count,
				);
				$total      += $count;
			}
		}

		$out = array(
			'total'     => $total,
			'breakdown' => $breakdown,
		);

		if ( '' !== $status ) {
			$ids    = wc_get_orders(
				array(
					'status'  => $status,
					'return'  => 'ids',
					'limit'   => 10,
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			);
			$orders = array();
			foreach ( $ids as $id ) {
				$order = wc_get_order( $id );
				if ( ! $order ) {
					continue;
				}
				$name     = trim( $order->get_formatted_billing_full_name() );
				$orders[] = array(
					'id'       => (int) $id,
					'number'   => (string) $order->get_order_number(),
					'name'     => '' !== $name ? $name : __( '（無姓名）', 'mo-ectools' ),
					'total'    => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
					'edit_url' => $order->get_edit_order_url(),
				);
			}
			$out['requested_status'] = $status;
			$out['orders']           = $orders;
		}

		return $out;
	}
}
