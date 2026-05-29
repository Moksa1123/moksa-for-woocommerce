<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayInvoice\Operations;

use MoksaWeb\Mowc\Modules\SmilepayInvoice\Api\Helper;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Invalid {

	
	public static function run( \WC_Order $order, string $reason ): array {
		$invoice_no = (string) $order->get_meta( Keys::SMILEPAY_INVOICE_NUMBER );
		if ( '' === $invoice_no ) {
			return [ 'ok' => false, 'message' => __( '此訂單沒有可作廢的 SmilePay 發票。', 'mo-ectools' ) ];
		}
		if ( in_array( $invoice_no, [ 'zero', 'negative' ], true ) ) {
			$order->delete_meta_data( Keys::SMILEPAY_INVOICE_NUMBER );
			$order->save();
			return [ 'ok' => true, 'message' => __( '清除「未開立」標記。', 'mo-ectools' ) ];
		}
		if ( $order->get_meta( Keys::SMILEPAY_INVOICE_INVALID_AT ) ) {
			return [ 'ok' => false, 'message' => __( 'SmilePay 發票已作廢。', 'mo-ectools' ) ];
		}
		$reason = trim( $reason );
		if ( '' === $reason ) {
			$reason = __( '訂單取消', 'mo-ectools' );
		}

		$inv_date = (string) $order->get_meta( Keys::SMILEPAY_INVOICE_DATE );
		// SmilePay invalid 要 YYYY/MM/DD 格式（從 stored 'YYYY-MM-DD HH:MM:SS' 拆出）
		$inv_date_only = '' !== $inv_date ? str_replace( '-', '/', substr( $inv_date, 0, 10 ) ) : '';

		$args = [
			'Grvc'          => Helper::grvc(),
			'Verify_key'    => Helper::verify_key(),
			'InvoiceNumber' => $invoice_no,
			'InvoiceDate'   => $inv_date_only,
			'types'         => 'Cancel',
			'CancelReason'  => mb_substr( $reason, 0, 20 ),
		];

		Helper::log( 'invalid request', [ 'order_id' => $order->get_id(), 'invoice' => $invoice_no ] );

		$result = Helper::post( Helper::PATH_INVALID, $args );

		if ( ! $result['ok'] ) {
			$msg = sprintf(
				/* translators: 1: error message, 2: status code */
				__( 'SmilePay 發票作廢失敗：%1$s（%2$s）', 'mo-ectools' ),
				$result['message'] ?? '',
				$result['status'] ?? ''
			);
			$order->add_order_note( $msg );
			$order->save();
			return [ 'ok' => false, 'message' => $msg ];
		}

		$order->update_meta_data( Keys::SMILEPAY_INVOICE_INVALID_AT, current_time( 'mysql' ) );
		$order->update_meta_data( Keys::SMILEPAY_INVOICE_INVALID_REASON, $reason );
		$order->add_order_note( sprintf(
			/* translators: 1: invoice number, 2: reason */
			__( 'SmilePay 發票 %1$s 已作廢 — 原因：%2$s', 'mo-ectools' ),
			$invoice_no,
			$reason
		) );
		$order->save();

		return [ 'ok' => true, 'message' => $result['message'] ?? '' ];
	}
}
