<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 單筆訂單明細 —— 通用 WC 欄位 + mo-ectools 台灣特有號碼（發票 / 物流 / 金流 / 取貨門市），
 * 整理成 AI / REST 好讀的結構。給「這筆發票開了嗎？物流單號多少？取哪間門市？」這類問題用。
 */
final class OrderDetails {

	/**
	 * @param string $term 訂單編號 / 發票 / 物流 / 金流號碼。
	 * @return array<string, mixed>|null 找不到回 null。
	 */
	public static function resolve( string $term ): ?array {
		$hits = OrderNumberLookup::resolve( $term, 1 );
		if ( empty( $hits ) ) {
			return null;
		}
		$order = wc_get_order( (int) $hits[0]['id'] );
		if ( ! $order ) {
			return null;
		}

		$items = [];
		foreach ( $order->get_items() as $item ) {
			$items[] = [
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
			];
		}

		$invoice  = SearchableKeys::field_value( $order, 'invoice' );
		$shipping = SearchableKeys::field_value( $order, 'shipping' );
		$payment  = SearchableKeys::field_value( $order, 'payment' );

		$name = trim( $order->get_formatted_billing_full_name() );
		$date = $order->get_date_created();

		return [
			'number'          => (string) $order->get_order_number(),
			'status'          => wc_get_order_status_name( $order->get_status() ),
			'date'            => $date ? $date->date_i18n( 'Y-m-d H:i' ) : '',
			'customer'        => '' !== $name ? $name : __( '（無姓名）', 'moksa-for-woocommerce' ),
			'phone'           => (string) $order->get_billing_phone(),
			'email'           => (string) $order->get_billing_email(),
			'payment_method'  => (string) $order->get_payment_method_title(),
			'shipping_method' => (string) $order->get_shipping_method(),
			'cvs_store'       => self::cvs_store( $order ),
			'total'           => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
			'items'           => $items,
			'invoice_number'  => $invoice,
			'invoice_issued'  => '' !== $invoice,
			'shipping_number' => $shipping,
			'payment_number'  => $payment,
			'edit_url'        => $order->get_edit_order_url(),
		];
	}

	/**
	 * 取貨門市名稱：先讀共用 key，沒有再退回各 provider 專屬 key。
	 *
	 * @param \WC_Order $order 訂單。
	 * @return string
	 */
	private static function cvs_store( \WC_Order $order ): string {
		$keys = [
			Keys::SHIPPING_CVS_STORE_NAME,
			Keys::NEWEBPAY_SHIPPING_STORE_NAME,
			Keys::SMILEPAY_SHIPPING_STORE_NAME,
		];
		foreach ( $keys as $key ) {
			$value = trim( (string) $order->get_meta( $key ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}
}
