<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * 從 RY Tools 或綠界官方外掛換過來的商店，舊訂單上留著它們寫的發票號、物流單號與門市。
 * 只讀不改：讓 Moksa 看得到這些資料，並把它們當成「已開過票／已建過單」。
 */
final class LegacyOrderData {

	/**
	 * 舊外掛開立、而且沒有作廢的發票。綠界官方作廢時會清空號碼；RY 作廢時刪除號碼，
	 * 0 元／負數／延後開立則寫入 zero、negative、delay，這三種都不是發票。
	 *
	 * @return array{number: string, date: string, random: string, source: string}|null
	 */
	public static function invoice( \WC_Order $order ): ?array {
		$number = trim( (string) $order->get_meta( '_wooecpay_invoice_no' ) );
		if ( '' !== $number ) {
			return [
				'number' => $number,
				'date'   => (string) $order->get_meta( '_wooecpay_invoice_date' ),
				'random' => (string) $order->get_meta( '_wooecpay_invoice_random_number' ),
				'source' => __( 'ECPay official plugin', 'moksa-for-woocommerce' ),
			];
		}
		$number = trim( (string) $order->get_meta( '_invoice_number' ) );
		if ( '' !== $number && ! in_array( $number, [ 'zero', 'negative', 'delay' ], true ) ) {
			return [
				'number' => $number,
				'date'   => (string) $order->get_meta( '_invoice_date' ),
				'random' => (string) $order->get_meta( '_invoice_random_number' ),
				'source' => __( 'RY Tools', 'moksa-for-woocommerce' ),
			];
		}
		return null;
	}

	public static function invoice_number( \WC_Order $order ): string {
		return self::invoice( $order )['number'] ?? '';
	}

	/**
	 * 自動開立前呼叫：舊外掛已開過票就跳過，並留一則訂單備註說明。
	 */
	public static function skip_issue_if_invoiced( \WC_Order $order ): bool {
		$legacy = self::invoice( $order );
		if ( null === $legacy ) {
			return false;
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: invoice number, 2: plugin name */
				__( 'No new invoice was issued: this order already has invoice %1$s, issued by the %2$s.', 'moksa-for-woocommerce' ),
				$legacy['number'],
				$legacy['source']
			)
		);
		return true;
	}

	/**
	 * 舊外掛建過的物流單。
	 *
	 * @return array<int, array{source: string, id: string, tracking: string, type: string, status: string}>
	 */
	public static function shipments( \WC_Order $order ): array {
		$list = [];
		$ry   = [
			'_ecpay_shipping_info'    => __( 'RY Tools (ECPay)', 'moksa-for-woocommerce' ),
			'_newebpay_shipping_info' => __( 'RY Tools (NewebPay)', 'moksa-for-woocommerce' ),
			'_smilepay_shipping_info' => __( 'RY Tools (SmilePay)', 'moksa-for-woocommerce' ),
		];
		foreach ( $ry as $key => $source ) {
			$info = $order->get_meta( $key );
			if ( ! is_array( $info ) ) {
				continue;
			}
			foreach ( $info as $id => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$list[] = [
					'source'   => $source,
					'id'       => (string) ( $row['ID'] ?? $id ),
					'tracking' => self::tracking_from( (string) ( $row['PaymentNo'] ?? '' ), (string) ( $row['ValidationNo'] ?? '' ), (string) ( $row['BookingNote'] ?? '' ), (string) ( $row['ID'] ?? $id ) ),
					'type'     => (string) ( $row['LogisticsSubType'] ?? $row['LogisticsType'] ?? '' ),
					'status'   => (string) ( $row['status_msg'] ?? '' ),
				];
			}
		}

		$id = trim( (string) $order->get_meta( '_wooecpay_logistic_AllPayLogisticsID' ) );
		if ( '' !== $id ) {
			$list[] = [
				'source'   => __( 'ECPay official plugin', 'moksa-for-woocommerce' ),
				'id'       => $id,
				'tracking' => self::tracking_from( (string) $order->get_meta( '_wooecpay_logistic_CVSPaymentNo' ), (string) $order->get_meta( '_wooecpay_logistic_CVSValidationNo' ), (string) $order->get_meta( '_wooecpay_logistic_BookingNote' ), $id ),
				'type'     => (string) $order->get_meta( '_wooecpay_logistic_LogisticsType' ),
				'status'   => (string) $order->get_meta( '_wooecpay_logistic_RtnMsg' ),
			];
		}
		return $list;
	}

	/** 最後一筆舊物流單的查件號碼，沒有回空字串。 */
	public static function tracking_no( \WC_Order $order ): string {
		$list = self::shipments( $order );
		return $list ? (string) end( $list )['tracking'] : '';
	}

	/**
	 * 舊外掛記下的取貨門市。
	 *
	 * @return array{id: string, name: string, address: string, phone: string}|null
	 */
	public static function store( \WC_Order $order ): ?array {
		$sources = [
			'_shipping_cvs_store_'       => '_shipping_cvs_store_ID',
			'_ecpay_logistic_cvs_store_' => '_ecpay_logistic_cvs_store_id',
		];
		foreach ( $sources as $prefix => $id_key ) {
			$id = trim( (string) $order->get_meta( $id_key ) );
			if ( '' === $id ) {
				continue;
			}
			return [
				'id'      => $id,
				'name'    => (string) $order->get_meta( $prefix . 'name' ),
				'address' => (string) $order->get_meta( $prefix . 'address' ),
				'phone'   => (string) $order->get_meta( $prefix . 'telephone' ),
			];
		}
		return null;
	}

	/** 超商用寄件代碼（加驗證碼），宅配用托運單號，都沒有才用物流商內部編號。 */
	private static function tracking_from( string $payment_no, string $validation_no, string $booking_note, string $id ): string {
		if ( '' !== $payment_no ) {
			return $payment_no . $validation_no;
		}
		return '' !== $booking_note ? $booking_note : $id;
	}
}
