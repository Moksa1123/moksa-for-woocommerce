<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayInvoice\Operations;

use Moksafowo\Modules\EcpayInvoice\Api\Helper;
use Moksafowo\Modules\Shared\Compat\LegacyOrderData;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 作廢綠界官方外掛開的舊發票。用 Moksa 綠界發票設定的商店帳號送出，
 * 所以兩邊要是同一組綠界帳號；作廢結果記在 Moksa 自己的 meta，舊外掛的資料不改。
 */
final class LegacyInvalid {

	public const AJAX_ACTION = 'moksafowo_ecpay_legacy_invoice_void';

	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'ajax' ] );
	}

	public static function ajax(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! \Moksafowo\Modules\Shared\Caps::user_can( \Moksafowo\Modules\Shared\Caps::INVOICE_VOID ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$reason   = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}
		$result = self::run( $order, $reason );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	/**
	 * @return array{ok: bool, message: string}
	 */
	public static function run( \WC_Order $order, string $reason ): array {
		$legacy = LegacyOrderData::invoice( $order );
		if ( null === $legacy || 'ecpay_official' !== $legacy['source_key'] ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no invoice from the ECPay official plugin to void.', 'moksa-for-woocommerce' ),
			];
		}
		$reason = trim( $reason );
		if ( '' === $reason ) {
			return [
				'ok'      => false,
				'message' => __( 'Please enter a reason for voiding.', 'moksa-for-woocommerce' ),
			];
		}

		$date   = substr( $legacy['date'], 0, 10 );
		$result = Helper::post(
			'/B2CInvoice/Invalid',
			[
				'MerchantID'  => Helper::merchant_id(),
				'InvoiceNo'   => $legacy['number'],
				'InvoiceDate' => '' !== $date ? $date : current_time( 'Y-m-d' ),
				'Reason'      => mb_substr( $reason, 0, 20 ),
			]
		);
		if ( ! $result['ok'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: invoice number, 2: error message */
					__( 'Invoice %1$s from the ECPay official plugin could not be voided: %2$s', 'moksa-for-woocommerce' ),
					$legacy['number'],
					$result['message']
				)
			);
			return [
				'ok'      => false,
				'message' => (string) $result['message'],
			];
		}

		$order->update_meta_data( Keys::LEGACY_INVOICE_VOIDED_AT, current_time( 'mysql' ) );
		$order->update_meta_data( Keys::LEGACY_INVOICE_VOID_REASON, $reason );
		$order->add_order_note(
			sprintf(
				/* translators: 1: invoice number, 2: reason */
				__( 'Invoice %1$s from the ECPay official plugin was voided — reason: %2$s', 'moksa-for-woocommerce' ),
				$legacy['number'],
				$reason
			)
		);
		$order->save();
		return [
			'ok'      => true,
			'message' => (string) $result['message'],
		];
	}
}
