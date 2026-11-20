<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayInvoice\Operations;

use Moksafowo\Modules\EcpayInvoice\Api\Helper;
use Moksafowo\Modules\Shared\Compat\LegacyOrderData;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 作廢綠界官方外掛或 woomp 開的舊發票。用 Moksa 綠界發票設定的商店帳號送出，
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
		// 跟開折讓用同一把鎖：折讓與作廢不同時跑。
		$result = LegacyAllowance::locked( $order->get_id(), static fn( \WC_Order $fresh ) => self::run( $fresh, $reason ) );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	/**
	 * @return array{ok: bool, message: string}
	 */
	public static function run( \WC_Order $order, string $reason ): array {
		$legacy = LegacyOrderData::invoice( $order );
		if ( null === $legacy || ! LegacyOrderData::ecpay_actionable( $legacy ) ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no ECPay invoice from an earlier plugin that can be voided here.', 'moksa-for-woocommerce' ),
			];
		}
		$reason = trim( $reason );
		if ( '' === $reason ) {
			return [
				'ok'      => false,
				'message' => __( 'Please enter a reason for voiding.', 'moksa-for-woocommerce' ),
			];
		}
		if ( LegacyAllowance::open_total( $order ) > 0 ) {
			return [
				'ok'      => false,
				'message' => __( 'This invoice has allowances. Void the allowances first.', 'moksa-for-woocommerce' ),
			];
		}

		// 綠界要正確的開立日期，錯了就「查無發票」；查不到寧可請店家到綠界後台，不猜日期。
		$date = LegacyInvoiceDate::resolve( $order, $legacy );
		if ( '' === $date ) {
			return [
				'ok'      => false,
				'message' => __( 'The issue date of this invoice could not be found, and ECPay needs it. Handle this invoice in the ECPay dashboard instead.', 'moksa-for-woocommerce' ),
			];
		}
		$result = Helper::post(
			'/B2CInvoice/Invalid',
			[
				'MerchantID'  => Helper::merchant_id(),
				'InvoiceNo'   => $legacy['number'],
				'InvoiceDate' => $date,
				'Reason'      => mb_substr( $reason, 0, 20 ),
			]
		);
		if ( ! $result['ok'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: invoice number, 2: plugin name, 3: error message */
					__( 'Invoice %1$s from the %2$s could not be voided: %3$s', 'moksa-for-woocommerce' ),
					$legacy['number'],
					$legacy['source'],
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
				/* translators: 1: invoice number, 2: plugin name, 3: reason */
				__( 'Invoice %1$s from the %2$s was voided — reason: %3$s', 'moksa-for-woocommerce' ),
				$legacy['number'],
				$legacy['source'],
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
