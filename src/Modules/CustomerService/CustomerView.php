<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\CustomerService;

use MoksaWeb\Mowc\Modules\OrderLookup\SearchableKeys;

defined( 'ABSPATH' ) || exit;

/**
 * 顧客視角的訂單摘要 —— 驗證通過後，只回「該訂單」且去敏的安全欄位。
 *
 * 刻意不重用後台 edit_shop_orders 的 ability / OrderDetails（那是店家視角、含敏感欄位）。
 * 號碼類查詢重用 SearchableKeys::field_value（純 meta 讀取工具，不 gate 權限）。
 */
final class CustomerView {

	/**
	 * @param int $order_id 已驗證的訂單 id。
	 * @return array<string,mixed>|null 找不到回 null。
	 */
	public static function summary( int $order_id ): ?array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
			return null;
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name' => (string) $item->get_name(),
				'qty'  => (int) $item->get_quantity(),
			);
		}

		$paid = $order->is_paid();
		$data = array(
			'number'         => (string) $order->get_order_number(),
			'status'         => wc_get_order_status_name( $order->get_status() ),
			'date'           => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d' ) : '',
			'total'          => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
			'paid'           => $paid,
			'items'          => $items,
			'payment_method' => (string) $order->get_payment_method_title(),
		);

		// 未付款才回 ATM 虛擬帳號 / 超商繳費代碼(讓顧客去繳費)。
		if ( ! $paid ) {
			$atm = SearchableKeys::field_value( $order, 'atm' );
			$cvs = SearchableKeys::field_value( $order, 'cvs' );
			if ( '' !== $atm ) {
				$data['atm_code'] = $atm;
			}
			if ( '' !== $cvs ) {
				$data['cvs_code'] = $cvs;
			}
		}

		$ship_method = '';
		foreach ( $order->get_shipping_methods() as $method ) {
			$ship_method = (string) $method->get_method_title();
			break;
		}
		if ( '' !== $ship_method ) {
			$data['shipping_method'] = $ship_method;
		}
		$ship_no = SearchableKeys::field_value( $order, 'shipping' );
		if ( '' !== $ship_no ) {
			$data['shipping_number'] = $ship_no;
		}

		// 發票號(有號碼即視為已開立);不回完整買受人 / 載具明細。
		$inv = SearchableKeys::field_value( $order, 'invoice' );
		if ( '' !== $inv ) {
			$data['invoice_number'] = $inv;
			$data['invoice_issued'] = true;
		}

		return $data;
	}
}
