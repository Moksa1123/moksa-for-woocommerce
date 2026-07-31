<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PaynowInvoice\Operations;

use Moksafowo\Modules\PaynowInvoice\Api\Helper;
use Moksafowo\Modules\PaynowInvoice\Api\Request;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Invalid {


	public static function run( \WC_Order $order, string $reason = '' ): array {
		$invoice_no = (string) $order->get_meta( Keys::PAYNOW_INVOICE_NUMBER );
		if ( '' === $invoice_no || 'zero' === $invoice_no || 'negative' === $invoice_no ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no PayNow invoice to void.', 'moksa-for-woocommerce' ),
			];
		}
		if ( $order->get_meta( Keys::PAYNOW_INVOICE_INVALID_AT ) ) {
			return [
				'ok'      => false,
				'message' => __( 'This invoice has already been voided.', 'moksa-for-woocommerce' ),
			];
		}

		Helper::log(
			'invalid request',
			[
				'order_id'   => $order->get_id(),
				'invoice_no' => $invoice_no,
				'reason'     => $reason,
			]
		);

		$resp = Request::cancel_invoice( Helper::mem_cid(), Helper::mem_password(), $invoice_no );
		if ( ! $resp['ok'] ) {
			$msg = sprintf(
				/* translators: 1: invoice number, 2: error */
				__( 'The PayNow invoice could not be voided (#%1$s): %2$s', 'moksa-for-woocommerce' ),
				$invoice_no,
				$resp['message']
			);
			$order->add_order_note( $msg );
			$order->save();
			return [
				'ok'      => false,
				'message' => $msg,
			];
		}

		$order->update_meta_data( Keys::PAYNOW_INVOICE_INVALID_AT, current_time( 'mysql' ) );
		$order->update_meta_data( Keys::PAYNOW_INVOICE_INVALID_REASON, $reason );
		$order->add_order_note(
			sprintf(
			/* translators: 1: invoice number, 2: reason */
				__( 'PayNow invoice #%1$s was voided: %2$s', 'moksa-for-woocommerce' ),
				$invoice_no,
				'' !== $reason ? $reason : __( 'No reason given', 'moksa-for-woocommerce' )
			)
		);
		$order->save();
		return [
			'ok'      => true,
			'message' => $invoice_no,
		];
	}
}
