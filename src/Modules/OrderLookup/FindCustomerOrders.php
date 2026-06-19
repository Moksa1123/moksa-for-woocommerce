<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 依顧客(email / 電話 / 姓名)查其所有訂單(唯讀,客服用)。走 wc_get_orders 的 's'
 * 搜尋(涵蓋帳單姓名 / email / 電話);email 另用 billing_email 精確比對提高命中。
 */
final class FindCustomerOrders {

	/**
	 * @param mixed $input { query: string }。
	 * @return array<string,mixed>
	 */
	public static function execute( $input ): array {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return array(
				'count'  => 0,
				'orders' => array(),
			);
		}
		$query = is_array( $input ) && isset( $input['query'] ) ? sanitize_text_field( (string) $input['query'] ) : '';
		if ( mb_strlen( $query ) < 2 ) {
			return array(
				'count'   => 0,
				'orders'  => array(),
				'message' => __( '請提供顧客 email、電話或姓名。', 'mo-ectools' ),
			);
		}

		$args = array(
			'limit'   => 20,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		);
		if ( is_email( $query ) ) {
			$args['billing_email'] = $query;
		} else {
			$args['s'] = $query;
		}

		$orders = wc_get_orders( $args );
		// email 精確查若無結果,退回 's' 模糊。
		if ( empty( $orders ) && isset( $args['billing_email'] ) ) {
			unset( $args['billing_email'] );
			$args['s'] = $query;
			$orders    = wc_get_orders( $args );
		}

		$rows  = array();
		$spent = 0.0;
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
			if ( $order->is_paid() ) {
				$spent += (float) $order->get_total();
			}
		}

		return array(
			'count'      => count( $rows ),
			'orders'     => $rows,
			'paid_total' => wc_price( $spent ) ? html_entity_decode( wp_strip_all_tags( wc_price( $spent ) ), ENT_QUOTES, 'UTF-8' ) : (string) $spent,
		);
	}
}
