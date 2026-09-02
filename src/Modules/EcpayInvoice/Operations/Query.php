<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayInvoice\Operations;

use Moksafowo\Modules\EcpayInvoice\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 向綠界查這張發票目前的狀態（B2CInvoice/GetIssue）。
 *
 * 為什麼需要：metabox 顯示的發票號碼、開立時間都是我們自己在開立當下寫進去的。
 * 如果開立時 API 逾時、或發票後來在綠界後台被作廢，站上完全不會知道 —— 商家
 * 看到的是我們的紀錄，不是財政部那邊的真相。發票涉及申報，這個落差不能只靠猜。
 *
 * 只讀不寫發票狀態：查到的結果寫進備註供商家判斷，不自動改本地資料，
 * 避免一次查詢就把商家手動修正過的欄位蓋掉。
 */
final class Query {

	public static function run( \WC_Order $order ): array {
		$invoice_no = (string) $order->get_meta( Keys::ECPAY_INVOICE_NUMBER );
		if ( '' === $invoice_no ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no invoice to look up yet.', 'moksa-for-woocommerce' ),
			];
		}

		// GetIssue 需要開立日期（Y-m-d）。用本地紀錄的開立時間，沒有就退回訂單日期。
		$issued_at = (string) $order->get_meta( Keys::ECPAY_INVOICE_ISSUED_AT );
		if ( '' === $issued_at ) {
			$created   = $order->get_date_created();
			$issued_at = $created ? $created->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		}
		$invoice_date = substr( str_replace( '/', '-', $issued_at ), 0, 10 );

		$res = Helper::post(
			'/B2CInvoice/GetIssue',
			[
				'MerchantID'  => Helper::merchant_id(),
				'InvoiceNo'   => $invoice_no,
				'InvoiceDate' => $invoice_date,
			]
		);

		if ( empty( $res['ok'] ) ) {
			return [
				'ok'      => false,
				'message' => (string) ( $res['message'] ?? __( 'ECPay could not be reached.', 'moksa-for-woocommerce' ) ),
			];
		}

		$d = (array) ( $res['data'] ?? [] );

		return [
			'ok'      => true,
			'message' => (string) ( $d['IIS_Invalid_Status'] ?? '' ) !== ''
				? __( 'This invoice has been voided at ECPay.', 'moksa-for-woocommerce' )
				: __( 'This invoice is valid at ECPay.', 'moksa-for-woocommerce' ),
			'data'    => $d,
			'lines'   => array_filter(
				[
					__( 'Invoice number', 'moksa-for-woocommerce' ) => $invoice_no,
					__( 'Issued at', 'moksa-for-woocommerce' )      => (string) ( $d['IIS_Create_Date'] ?? '' ),
					__( 'Invoice amount', 'moksa-for-woocommerce' ) => (string) ( $d['IIS_Sales_Amount'] ?? '' ),
					__( 'Random code', 'moksa-for-woocommerce' )    => (string) ( $d['IIS_Random_Number'] ?? '' ),
					__( 'Void status', 'moksa-for-woocommerce' )    => (string) ( $d['IIS_Invalid_Status'] ?? '' ),
				],
				static fn( string $v ): bool => '' !== $v
			),
		];
	}
}
