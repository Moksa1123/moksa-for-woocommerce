<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EzpayInvoice\Operations;

use MoksaWeb\Mowc\Modules\EzpayInvoice\Api\Helper;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Invalid {

	
	public static function run( \WC_Order $order, string $reason ): array {
		$invoice_no = (string) $order->get_meta( Keys::EZPAY_INVOICE_NUMBER );
		if ( '' === $invoice_no ) {
			return [ 'ok' => false, 'message' => __( '此訂單沒有可作廢的 ezPay 發票。', 'mo-ectools' ) ];
		}
		// zero / negative 是 mowp 自己 mark 的 marker，不打 ezPay API，直接清掉 meta
		if ( in_array( $invoice_no, [ 'zero', 'negative' ], true ) ) {
			$order->delete_meta_data( Keys::EZPAY_INVOICE_NUMBER );
			$order->save();
			return [ 'ok' => true, 'message' => __( '清除「未開立」標記。', 'mo-ectools' ) ];
		}
		if ( $order->get_meta( Keys::EZPAY_INVALID_AT ) ) {
			return [ 'ok' => false, 'message' => __( 'ezPay 發票已作廢。', 'mo-ectools' ) ];
		}
		$reason = trim( $reason );
		if ( '' === $reason ) {
			$reason = __( '訂單取消', 'mo-ectools' );
		}

		$now = new \DateTime( 'now', new \DateTimeZone( 'Asia/Taipei' ) );

		$args = [
			'RespondType'   => 'JSON',
			'Version'       => '1.0',
			'TimeStamp'     => (string) $now->getTimestamp(),
			'InvoiceNumber' => $invoice_no,
			'InvalidReason' => mb_substr( $reason, 0, 20 ),
		];

		Helper::log( 'invalid request', [ 'order_id' => $order->get_id(), 'invoice' => $invoice_no ] );

		$result = Helper::post( '/Api/invoice_invalid', $args );

		if ( ! $result['ok'] ) {
			$msg = sprintf(
				/* translators: 1: error message, 2: status code */
				__( 'ezPay 發票作廢失敗：%1$s（%2$s）', 'mo-ectools' ),
				$result['message'] ?? '',
				$result['status'] ?? ''
			);
			$order->add_order_note( $msg );
			$order->save();
			return [ 'ok' => false, 'message' => $msg ];
		}

		$order->update_meta_data( Keys::EZPAY_INVALID_AT, current_time( 'mysql' ) );
		$order->update_meta_data( Keys::EZPAY_INVALID_REASON, $reason );
		$order->add_order_note( sprintf(
			/* translators: 1: invoice number, 2: reason */
			__( 'ezPay 發票 %1$s 已作廢 — 原因：%2$s', 'mo-ectools' ),
			$invoice_no,
			$reason
		) );
		$order->save();

		return [ 'ok' => true, 'message' => $result['message'] ?? '' ];
	}
}
