<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 進階訂單列表(唯讀,報表型)。依狀態 / 日期區間 / 金流方式篩選,回傳精簡訂單清單。
 * 純走 wc_get_orders(HPOS 原生),不修改任何資料。
 */
final class ListOrders {

	const MAX = 50;

	/**
	 * @param mixed $input { status?: string, payment_method?: string, date_from?: string, date_to?: string, limit?: int }。
	 * @return array<string,mixed>
	 */
	public static function run( $input ): array {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return array(
				'count'  => 0,
				'orders' => array(),
			);
		}
		$input = is_array( $input ) ? $input : array();

		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : 20;
		$limit = max( 1, min( self::MAX, $limit ) );

		$args = array(
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		);

		$status = isset( $input['status'] ) ? str_replace( 'wc-', '', sanitize_key( (string) $input['status'] ) ) : '';
		if ( '' !== $status && ! in_array( $status, array( 'all', 'any' ), true ) ) {
			$args['status'] = $status;
		}

		$payment = isset( $input['payment_method'] ) ? sanitize_text_field( (string) $input['payment_method'] ) : '';
		if ( '' !== $payment ) {
			$args['payment_method'] = $payment;
		}

		$from = isset( $input['date_from'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $input['date_from'] ) : '';
		$to   = isset( $input['date_to'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $input['date_to'] ) : '';
		if ( '' !== $from && '' !== $to ) {
			$args['date_created'] = $from . '...' . $to;
		} elseif ( '' !== $from ) {
			$args['date_created'] = '>=' . $from;
		} elseif ( '' !== $to ) {
			$args['date_created'] = '<=' . $to;
		}

		$orders = wc_get_orders( $args );
		$rows   = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$name   = trim( $order->get_formatted_billing_full_name() );
			$date   = $order->get_date_created();
			$rows[] = array(
				'number'         => (string) $order->get_order_number(),
				'status'         => wc_get_order_status_name( $order->get_status() ),
				'date'           => $date ? $date->date_i18n( 'Y-m-d' ) : '',
				'total'          => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
				'customer'       => '' !== $name ? $name : __( '（無姓名）', 'mo-ectools' ),
				'payment_method' => (string) $order->get_payment_method_title(),
			);
		}

		return array(
			'count'  => count( $rows ),
			'orders' => $rows,
		);
	}
}
