<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PaynowInvoice\Operations;

use Moksafowo\Modules\PaynowInvoice\Api\Helper;
use Moksafowo\Modules\PaynowInvoice\Api\Request;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 向 PayNow 查這張發票目前的狀態（PayNow_EInvoice v1.5 的 Invoice_Info）。
 *
 * 站上顯示的發票資料都是開立當下寫進去的；開立時逾時、或發票後來在 PayNow
 * 後台被作廢，站上都不會知道。發票要申報，這個落差不能靠猜。
 *
 * 只讀不覆蓋本地欄位。
 */
final class Query {

	public static function run( \WC_Order $order ): array {
		$invoice_no = (string) $order->get_meta( Keys::PAYNOW_INVOICE_NUMBER );
		if ( '' === $invoice_no ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no invoice to look up yet.', 'moksa-for-woocommerce' ),
				'lines'   => [],
			];
		}

		$resp = Request::select_invoice( Helper::mem_cid(), Helper::mem_password(), $invoice_no );
		if ( empty( $resp['ok'] ) ) {
			return [
				'ok'      => false,
				'message' => (string) ( $resp['message'] ?? __( 'PayNow could not be reached.', 'moksa-for-woocommerce' ) ),
				'lines'   => [],
			];
		}

		// Invoice_Info：開立,總額／作廢／折讓,總額,未確認折讓,已確認折讓
		$parts  = (array) ( $resp['parts'] ?? [] );
		$get    = static fn( int $i ): string => isset( $parts[ $i ] ) ? (string) $parts[ $i ] : '';
		$labels = [
			'開立' => __( 'Issued', 'moksa-for-woocommerce' ),
			'作廢' => __( 'Voided', 'moksa-for-woocommerce' ),
			'折讓' => __( 'Allowance', 'moksa-for-woocommerce' ),
		];

		return [
			'ok'      => true,
			'message' => __( 'PayNow holds this invoice.', 'moksa-for-woocommerce' ),
			'lines'   => array_filter(
				[
					__( 'Invoice number', 'moksa-for-woocommerce' ) => $invoice_no,
					__( 'Issuing status', 'moksa-for-woocommerce' ) => $labels[ $get( 0 ) ] ?? $get( 0 ),
					__( 'Invoice amount', 'moksa-for-woocommerce' ) => $get( 1 ),
					__( 'Unconfirmed allowance amount', 'moksa-for-woocommerce' ) => $get( 2 ),
					__( 'Confirmed allowance amount', 'moksa-for-woocommerce' ) => $get( 3 ),
				],
				static fn( string $v ): bool => '' !== $v
			),
		];
	}
}
