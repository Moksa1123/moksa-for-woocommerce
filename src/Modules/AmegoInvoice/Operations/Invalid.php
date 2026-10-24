<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\AmegoInvoice\Operations;

use Moksafowo\Modules\AmegoInvoice\Api\Helper;
use Moksafowo\Modules\AmegoInvoice\Api\Request;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Invalid {

	public const RETRY_HOOK = 'moksafowo_amego_invoice_retry_invalid';

	private const RETRY_INTERVAL = 30 * MINUTE_IN_SECONDS;

	private const RETRY_MAX = 8;

	/** 排程重試：折讓作廢處理完之前發票作廢會被拒，隔一段時間再試。 */
	public static function retry( int $order_id, string $reason = '', int $attempt = 1 ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order->get_meta( Keys::AMEGO_INVOICE_INVALID_AT ) ) {
			return;
		}
		self::run( $order, $reason, $attempt );
	}

	/** 排程下一次重試；已到上限就回 null，讓呼叫端照一般失敗處理。 */
	private static function schedule_retry( \WC_Order $order, string $invoice_no, string $reason, int $attempt ): ?array {
		if ( $attempt >= self::RETRY_MAX || ! function_exists( 'as_schedule_single_action' ) ) {
			return null;
		}
		as_schedule_single_action( time() + self::RETRY_INTERVAL, self::RETRY_HOOK, [ $order->get_id(), $reason, $attempt + 1 ], 'moksa-for-woocommerce' );
		if ( 0 === $attempt ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: invoice number */
					__( 'The Amego allowance void is still being processed. Invoice #%s will be voided automatically once it finishes.', 'moksa-for-woocommerce' ),
					$invoice_no
				)
			);
			$order->save();
		}
		return [
			'ok'      => false,
			'message' => __( 'The allowance void is still being processed by Amego. The invoice will be voided automatically shortly.', 'moksa-for-woocommerce' ),
		];
	}

	public static function run( \WC_Order $order, string $reason = '', int $attempt = 0 ): array {
		$invoice_no = (string) $order->get_meta( Keys::AMEGO_INVOICE_NUMBER );
		if ( '' === $invoice_no || 'zero' === $invoice_no || 'negative' === $invoice_no ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no Amego invoice to void.', 'moksa-for-woocommerce' ),
			];
		}
		if ( $order->get_meta( Keys::AMEGO_INVOICE_INVALID_AT ) ) {
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

		// 有效的折讓還在時光貿不讓作廢發票（3050141），先把折讓作廢。
		$voided = AllowanceInvalid::void_all( $order, $reason );
		if ( ! $voided['ok'] ) {
			return ! empty( $voided['retry'] )
				? ( self::schedule_retry( $order, $invoice_no, $reason, $attempt ) ?? $voided )
				: $voided;
		}

		// f0501 接受 array of {CancelInvoiceNumber}
		$resp = Request::post(
			'/json/f0501',
			[
				[ 'CancelInvoiceNumber' => $invoice_no ],
			]
		);

		// 折讓作廢是排隊處理（實測約 1～1.5 小時完成），處理完之前作廢發票回 3050141 已存在折讓單；
		// 3050131 是發票本身還在開立／作廢處理中。這兩種都晚點再試。
		if ( ! $resp['ok'] && in_array( (int) ( $resp['code'] ?? 0 ), [ 3050141, 3050131 ], true ) ) {
			$pending = self::schedule_retry( $order, $invoice_no, $reason, $attempt );
			if ( null !== $pending ) {
				return $pending;
			}
		}

		if ( ! $resp['ok'] ) {
			$msg = sprintf(
				/* translators: 1: invoice number, 2: error */
				__( 'The Amego invoice could not be voided (#%1$s): %2$s', 'moksa-for-woocommerce' ),
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
				__( 'Amego invoice #%1$s was voided: %2$s', 'moksa-for-woocommerce' ),
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
