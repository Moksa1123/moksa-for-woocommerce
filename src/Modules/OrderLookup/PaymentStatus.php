<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單付款狀態(唯讀)。通用回傳已記錄的付款資訊(付款方式、是否已付、交易序號、卡末四、
 * ATM 虛擬帳號、超商繳費代碼);若是藍新金流訂單,額外向藍新做 B02 即時查詢。
 *
 * 號碼類欄位重用 SearchableKeys::field_value(payment / card / atm / cvs)— 不受搜尋開關 gate。
 */
final class PaymentStatus {

	/**
	 * @param mixed $input { order: string }。
	 * @return array<string,mixed>
	 */
	public static function execute( $input ): array {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return array();
		}
		$ref   = is_array( $input ) && isset( $input['order'] ) ? (string) $input['order'] : '';
		$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return array( 'message' => __( '找不到訂單。', 'mo-ectools' ) );
		}

		$date_paid = $order->get_date_paid();
		$out       = array(
			'order'          => (string) $order->get_order_number(),
			'payment_method' => (string) $order->get_payment_method_title(),
			'paid'           => $order->is_paid(),
			'status'         => wc_get_order_status_name( $order->get_status() ),
			'total'          => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
			'transaction_no' => SearchableKeys::field_value( $order, 'payment' ),
			'card_last4'     => SearchableKeys::field_value( $order, 'card' ),
			'atm_account'    => SearchableKeys::field_value( $order, 'atm' ),
			'cvs_code'       => SearchableKeys::field_value( $order, 'cvs' ),
			'date_paid'      => $date_paid ? $date_paid->date_i18n( 'Y-m-d H:i' ) : '',
		);

		$live = self::newebpay_live( $order );
		if ( null !== $live ) {
			$out['live_query'] = $live;
		}

		return $out;
	}

	/**
	 * 藍新訂單 → B02 即時查詢交易狀態。非藍新或查不到回 null。
	 *
	 * @param \WC_Order $order 訂單。
	 * @return array<string,mixed>|null
	 */
	private static function newebpay_live( \WC_Order $order ): ?array {
		if ( 0 !== strpos( (string) $order->get_payment_method(), 'moksafowo_newebpay' ) ) {
			return null;
		}
		$class = 'MoksaWeb\\Mowc\\Modules\\Newebpay\\Api\\PaymentRequest';
		if ( ! class_exists( $class ) ) {
			return null;
		}
		$mtn = (string) $order->get_meta( \MoksaWeb\Mowc\Order\Meta\Keys::NEWEBPAY_MERCHANT_ORDER_NO );
		if ( '' === $mtn ) {
			return null;
		}

		$result = call_user_func( array( $class, 'query' ), $mtn, (int) round( (float) $order->get_total() ) );
		if ( empty( $result['ok'] ) ) {
			return array(
				'ok'      => false,
				'message' => (string) ( $result['message'] ?? __( '查詢失敗', 'mo-ectools' ) ),
			);
		}
		$data = (array) ( $result['data'] ?? array() );
		return array(
			'ok'           => true,
			'trade_status' => (string) ( $data['TradeStatus'] ?? '' ),
			'payment_type' => (string) ( $data['PaymentType'] ?? '' ),
			'amount'       => (string) ( $data['Amt'] ?? '' ),
			'paid_time'    => (string) ( $data['PayTime'] ?? '' ),
		);
	}
}
