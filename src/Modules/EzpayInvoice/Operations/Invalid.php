<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EzpayInvoice\Operations;

use Moksafowo\Modules\EzpayInvoice\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Invalid {


	public static function run( \WC_Order $order, string $reason ): array {
		$invoice_no = (string) $order->get_meta( Keys::EZPAY_INVOICE_NUMBER );
		if ( '' === $invoice_no ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no ezPay invoice to void.', 'moksa-for-woocommerce' ),
			];
		}
		// zero / negative 是本外掛自己 mark 的 marker，不打 ezPay API，直接清掉 meta
		if ( in_array( $invoice_no, [ 'zero', 'negative' ], true ) ) {
			$order->delete_meta_data( Keys::EZPAY_INVOICE_NUMBER );
			$order->save();
			return [
				'ok'      => true,
				'message' => __( 'Cleared the not-issued marker.', 'moksa-for-woocommerce' ),
			];
		}
		if ( $order->get_meta( Keys::EZPAY_INVALID_AT ) ) {
			return [
				'ok'      => false,
				'message' => __( 'The ezPay invoice was voided.', 'moksa-for-woocommerce' ),
			];
		}
		$reason = trim( $reason );
		if ( '' === $reason ) {
			$reason = __( 'Order cancelled', 'moksa-for-woocommerce' );
		}

		$now = new \DateTime( 'now', new \DateTimeZone( 'Asia/Taipei' ) );

		$args = [
			'RespondType'   => 'JSON',
			'Version'       => '1.0',
			'TimeStamp'     => (string) $now->getTimestamp(),
			'InvoiceNumber' => $invoice_no,
			'InvalidReason' => mb_substr( $reason, 0, 20 ),
		];

		Helper::log(
			'invalid request',
			[
				'order_id' => $order->get_id(),
				'invoice'  => $invoice_no,
			]
		);

		$result = Helper::post( '/Api/invoice_invalid', $args );

		if ( ! $result['ok'] ) {
			$msg = sprintf(
				/* translators: 1: error message, 2: status code */
				__( 'The ezPay invoice could not be voided: %1$s (%2$s)', 'moksa-for-woocommerce' ),
				$result['message'] ?? '',
				$result['status'] ?? ''
			);
			$order->add_order_note( $msg );
			$order->save();
			return [
				'ok'      => false,
				'message' => $msg,
			];
		}

		$order->update_meta_data( Keys::EZPAY_INVALID_AT, current_time( 'mysql' ) );
		$order->update_meta_data( Keys::EZPAY_INVALID_REASON, $reason );
		$order->add_order_note(
			sprintf(
			/* translators: 1: invoice number, 2: reason */
				__( 'ezPay invoice %1$s was voided — reason: %2$s', 'moksa-for-woocommerce' ),
				$invoice_no,
				$reason
			)
		);
		$order->save();

		return [
			'ok'      => true,
			'message' => $result['message'] ?? '',
		];
	}
}
