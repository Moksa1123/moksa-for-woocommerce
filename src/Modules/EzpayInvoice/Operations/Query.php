<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EzpayInvoice\Operations;

use Moksafowo\Modules\EzpayInvoice\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 向 ezPay 查這張發票目前的狀態（Api/invoice_search，EZP_INVI_1.2.2 第八章）。
 *
 * 站上顯示的發票資料都是開立當下寫進去的。開立時 API 逾時、或發票後來在 ezPay
 * 後台被作廢，站上不會知道 —— 發票要申報，這個落差不能靠猜。
 *
 * 用 SearchType=0（發票號碼 + 隨機碼）：這兩個值開立時都存下來了，比用訂單編號
 * 加金額可靠（金額四捨五入或後續改單都可能對不上）。
 *
 * 只讀不覆蓋本地欄位，避免一次查詢把商家手動修正過的資料蓋掉。
 */
final class Query {

	public static function run( \WC_Order $order ): array {
		$invoice_no = (string) $order->get_meta( Keys::EZPAY_INVOICE_NUMBER );
		$random     = (string) $order->get_meta( Keys::EZPAY_RANDOM_NUM );

		if ( '' === $invoice_no || '' === $random ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no invoice to look up yet.', 'moksa-for-woocommerce' ),
				'lines'   => [],
			];
		}

		$res = Helper::post(
			'/Api/invoice_search',
			[
				'RespondType'   => 'JSON',
				'Version'       => '1.3',
				'TimeStamp'     => (string) time(),
				'SearchType'    => '0',
				'InvoiceNumber' => $invoice_no,
				'RandomNum'     => $random,
			]
		);

		if ( empty( $res['ok'] ) ) {
			return [
				'ok'      => false,
				'message' => (string) ( $res['message'] ?? __( 'ezPay could not be reached.', 'moksa-for-woocommerce' ) ),
				'lines'   => [],
			];
		}

		$d = (array) ( $res['data'] ?? [] );

		// InvoiceStatus：1=已開立 2=已作廢 3=已註銷 4=待開立
		$status_map = [
			'1' => __( 'Issued', 'moksa-for-woocommerce' ),
			'2' => __( 'Voided', 'moksa-for-woocommerce' ),
			'3' => __( 'Cancelled', 'moksa-for-woocommerce' ),
			'4' => __( 'Waiting to be issued', 'moksa-for-woocommerce' ),
		];
		$status     = (string) ( $d['InvoiceStatus'] ?? '' );

		return [
			'ok'      => true,
			'message' => $status_map[ $status ] ?? $status,
			'lines'   => array_filter(
				[
					__( 'Invoice number', 'moksa-for-woocommerce' ) => (string) ( $d['InvoiceNumber'] ?? $invoice_no ),
					__( 'Issuing status', 'moksa-for-woocommerce' ) => $status_map[ $status ] ?? $status,
					__( 'Issued at', 'moksa-for-woocommerce' )      => (string) ( $d['CreateTime'] ?? '' ),
					__( 'Invoice amount', 'moksa-for-woocommerce' ) => (string) ( $d['TotalAmt'] ?? '' ),
					__( 'Random code', 'moksa-for-woocommerce' )    => (string) ( $d['RandomNum'] ?? $random ),
				],
				static fn( string $v ): bool => '' !== $v
			),
		];
	}
}
