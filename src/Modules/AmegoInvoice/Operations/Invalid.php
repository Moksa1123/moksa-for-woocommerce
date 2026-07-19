<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\AmegoInvoice\Operations;

use Moksafowo\Modules\AmegoInvoice\Api\Helper;
use Moksafowo\Modules\AmegoInvoice\Api\Request;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Invalid {

	public static function run( \WC_Order $order, string $reason = '' ): array {
		$invoice_no = (string) $order->get_meta( Keys::AMEGO_INVOICE_NUMBER );
		if ( '' === $invoice_no || 'zero' === $invoice_no || 'negative' === $invoice_no ) {
			return [
				'ok'      => false,
				'message' => __( '此訂單沒有可作廢的 Amego 發票。', 'moksa-for-woocommerce' ),
			];
		}
		if ( $order->get_meta( Keys::AMEGO_INVOICE_INVALID_AT ) ) {
			return [
				'ok'      => false,
				'message' => __( '此發票已作廢。', 'moksa-for-woocommerce' ),
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

		// f0501 接受 array of {CancelInvoiceNumber}
		$resp = Request::post(
			'/json/f0501',
			[
				[ 'CancelInvoiceNumber' => $invoice_no ],
			]
		);

		if ( ! $resp['ok'] ) {
			$msg = sprintf(
				/* translators: 1: invoice number, 2: error */
				__( 'Amego 發票作廢失敗 (#%1$s)：%2$s', 'moksa-for-woocommerce' ),
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

		$order->update_meta_data( Keys::AMEGO_INVOICE_INVALID_AT, current_time( 'mysql' ) );
		$order->update_meta_data( Keys::AMEGO_INVOICE_INVALID_REASON, $reason );
		$order->add_order_note(
			sprintf(
			/* translators: 1: invoice number, 2: reason */
				__( 'Amego 發票已作廢 (#%1$s)：%2$s', 'moksa-for-woocommerce' ),
				$invoice_no,
				'' !== $reason ? $reason : __( '無原因', 'moksa-for-woocommerce' )
			)
		);
		$order->save();
		return [
			'ok'      => true,
			'message' => $invoice_no,
		];
	}
}
