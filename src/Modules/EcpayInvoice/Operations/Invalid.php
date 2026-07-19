<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayInvoice\Operations;

use Moksafowo\Modules\EcpayInvoice\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Invalid {


	public static function run( \WC_Order $order, string $reason ): array {
		$inv = (string) $order->get_meta( Keys::ECPAY_INVOICE_NUMBER );
		if ( '' === $inv ) {
			return [
				'ok'      => false,
				'message' => __( '此訂單沒有可作廢的發票。', 'moksa-for-woocommerce' ),
			];
		}
		if ( $order->get_meta( Keys::ECPAY_INVOICE_INVALID_AT ) ) {
			return [
				'ok'      => false,
				'message' => __( '發票已作廢。', 'moksa-for-woocommerce' ),
			];
		}
		$reason = trim( $reason );
		if ( '' === $reason ) {
			return [
				'ok'      => false,
				'message' => __( '請輸入作廢原因。', 'moksa-for-woocommerce' ),
			];
		}

		$data = [
			'MerchantID'  => Helper::merchant_id(),
			'InvoiceNo'   => $inv,
			'InvoiceDate' => (string) ( $order->get_meta( Keys::ECPAY_INVOICE_ISSUED_AT ) ?: current_time( 'Y-m-d' ) ),
			'Reason'      => mb_substr( $reason, 0, 20 ),
		];

		$result = Helper::post( '/B2CInvoice/Invalid', $data );
		if ( ! $result['ok'] ) {
			$order->add_order_note(
				sprintf(
				/* translators: %s: error message */
					__( '綠界發票作廢失敗：%s', 'moksa-for-woocommerce' ),
					$result['message']
				)
			);
			return [
				'ok'      => false,
				'message' => $result['message'],
			];
		}

		$order->update_meta_data( Keys::ECPAY_INVOICE_INVALID_AT, current_time( 'mysql' ) );
		$order->update_meta_data( Keys::ECPAY_INVOICE_INVALID_REASON, $reason );
		$order->add_order_note(
			sprintf(
			/* translators: 1: invoice number, 2: reason */
				__( '綠界發票 %1$s 已作廢 — 原因：%2$s', 'moksa-for-woocommerce' ),
				$inv,
				$reason
			)
		);
		$order->save();

		return [
			'ok'      => true,
			'message' => $result['message'],
		];
	}
}
