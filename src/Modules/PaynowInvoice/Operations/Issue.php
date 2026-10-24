<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PaynowInvoice\Operations;

use Moksafowo\Modules\PaynowInvoice\Api\Helper;
use Moksafowo\Modules\PaynowInvoice\Api\Request;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Issue {


	public static function run( \WC_Order $order ): array {
		$existing = (string) $order->get_meta( Keys::PAYNOW_INVOICE_NUMBER );
		if ( '' !== $existing ) {
			return [
				'ok'      => false,
				'message' => __( 'This order already has an invoice, so another cannot be issued.', 'moksa-for-woocommerce' ),
			];
		}

		$type = (string) $order->get_meta( Keys::INVOICE_TYPE );
		if ( '' === $type ) {
			return [
				'ok'      => false,
				'message' => __( 'No invoice type is set on this order.', 'moksa-for-woocommerce' ),
			];
		}

		if ( ! Helper::has_credentials() ) {
			return [
				'ok'      => false,
				'message' => __( 'The PayNow invoice credentials are not set.', 'moksa-for-woocommerce' ),
			];
		}

		$total = (int) round( (float) $order->get_total() - (float) $order->get_total_refunded(), 0 );
		if ( $total <= 0 ) {
			$order->update_meta_data( Keys::PAYNOW_INVOICE_NUMBER, 'zero' );
			$order->add_order_note( __( 'The order total is zero or negative, so no PayNow invoice was issued.', 'moksa-for-woocommerce' ) );
			$order->save();
			return [
				'ok'      => false,
				'message' => __( 'The order total is zero or negative.', 'moksa-for-woocommerce' ),
			];
		}

		$orderno = Helper::generate_orderno( $order->get_id() );
		$rows    = self::build_csv_rows( $order, $orderno );
		if ( empty( $rows ) ) {
			return [
				'ok'      => false,
				'message' => __( 'The order has no items to invoice.', 'moksa-for-woocommerce' ),
			];
		}

		$csv     = implode( "\n", $rows );
		$csv_b64 = rawurlencode( base64_encode( $csv ) );

		Helper::log(
			'issue request',
			[
				'order_id' => $order->get_id(),
				'orderno'  => $orderno,
				'rows'     => count( $rows ),
				'total'    => $total,
			]
		);

		$resp = Request::upload_invoice_patch( Helper::mem_cid(), Helper::mem_password(), $csv_b64 );

		if ( ! $resp['ok'] ) {
			$msg = sprintf(
				/* translators: %s: error message */
				__( 'The PayNow invoice could not be issued: %s', 'moksa-for-woocommerce' ),
				$resp['message']
			);
			$order->update_meta_data( Keys::PAYNOW_INVOICE_STATUS, 'F' );
			$order->add_order_note( $msg );
			$order->save();
			return [
				'ok'      => false,
				'message' => $msg,
			];
		}

		// returnStr 範例：S_1,20170630001_AA12345678
		// items 內每筆 "orderno_invoiceNo"
		$invoice_no = '';
		foreach ( $resp['items'] as $item ) {
			$pair = explode( '_', $item, 2 );
			if ( isset( $pair[1] ) && $pair[0] === $orderno ) {
				$invoice_no = $pair[1];
				break;
			}
		}
		// 後備：取第一筆 _ 後的字串
		if ( '' === $invoice_no && ! empty( $resp['items'] ) ) {
			$first      = explode( '_', $resp['items'][0], 2 );
			$invoice_no = $first[1] ?? '';
		}

		if ( '' === $invoice_no ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: raw API response text from PayNow */
					__( 'PayNow reported success but the invoice number could not be read. Raw response: %s', 'moksa-for-woocommerce' ),
					$resp['raw']
				)
			);
			$order->save();
			return [
				'ok'      => false,
				'message' => 'no invoice number returned',
			];
		}

		$order->update_meta_data( Keys::PAYNOW_INVOICE_NUMBER, $invoice_no );
		$order->update_meta_data( Keys::PAYNOW_INVOICE_ORDER_NO, $orderno );
		$order->update_meta_data( Keys::PAYNOW_INVOICE_STATUS, 'S' );
		$order->update_meta_data( Keys::PAYNOW_INVOICE_ISSUED_AT, current_time( 'mysql' ) );
		$order->update_meta_data( Keys::INVOICE_PROVIDER, 'paynow' );
		$order->delete_meta_data( Keys::PAYNOW_INVOICE_SCHEDULED_AT );

		$order->add_order_note(
			sprintf(
			/* translators: 1: invoice number, 2: orderno */
				__( 'PayNow invoice issued — number %1$s (submission number %2$s)', 'moksa-for-woocommerce' ),
				$invoice_no,
				$orderno
			)
		);
		$order->save();

		return [
			'ok'             => true,
			'message'        => $invoice_no,
			'invoice_number' => $invoice_no,
		];
	}


	private static function build_csv_rows( \WC_Order $order, string $orderno ): array {
		$type      = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$carrier   = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$cnum      = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );
		$love_code = (string) $order->get_meta( Keys::INVOICE_LOVE_CODE );

		$buyer_id   = '';
		$carrier_t  = '';
		$carrier_1  = '';
		$carrier_2  = '';
		$love_param = '';

		switch ( $type ) {
			case 'b2c_carrier':
				switch ( $carrier ) {
					case 'mobile':  // 手機條碼
						$carrier_t = '3J0002';
						$carrier_1 = $cnum;
						$carrier_2 = $cnum;
						break;
					case 'cert':    // 自然人憑證
						$carrier_t = 'CQ0001';
						$carrier_1 = $cnum;
						$carrier_2 = $cnum;
						break;
					case 'member':  // 會員載具
					case 'paper':
					default:
						break;
				}
				break;
			case 'b2b':
				$buyer_id = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
				break;
			case 'b2c_donate':
				$love_param = $love_code;
				break;
		}

		$buyer_name  = trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		$buyer_email = (string) $order->get_billing_email();
		$buyer_phone = preg_replace( '/[^0-9]/', '', (string) $order->get_billing_phone() ) ?? '';
		$buyer_add   = $order->get_billing_address_1() . $order->get_billing_address_2();
		// buyer_add 有值 PayNow 就會寄紙本；只有紙本（沒有載具）與統編發票要寄。其餘加 BRING 前綴：留地址、不寄送。
		if ( '' !== $buyer_add && ! ( 'b2b' === $type || ( 'b2c_carrier' === $type && '' === $carrier_t ) ) ) {
			$buyer_add = 'BRING ' . $buyer_add;
		}

		if ( 'b2b' === $type ) {
			$company    = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
			$buyer_name = '' !== $company ? $company : $buyer_name;
		}

		// 明細要等於實際應開金額：各項扣掉已退的部分、加上費用項目，沒對應到項目的退款列成一筆負數。
		$total_refunded = (float) $order->get_total_refunded();

		$rows = [];
		foreach ( $order->get_items( [ 'line_item' ] ) as $item ) {
			$item_refunded   = (float) $order->get_total_refunded_for_item( $item->get_id(), 'line_item' );
			$total_refunded -= $item_refunded;
			$qty             = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item->get_id(), 'line_item' );
			$total           = (float) $item->get_total() - $item_refunded;
			if ( $qty <= 0 || 0.0 === $total ) {
				continue;
			}
			$unit_price = $qty > 0 ? round( $total / $qty, 0 ) : 0;
			$rows[]     = self::format_csv_row(
				[
					$orderno,
					$buyer_id,
					mb_substr( $buyer_name, 0, 30 ),
					mb_substr( $buyer_add, 0, 50 ),
					mb_substr( $buyer_phone, 0, 10 ),
					mb_substr( $buyer_email, 0, 80 ),
					$carrier_t,
					$carrier_1,
					$carrier_2,
					$love_param,
					mb_substr( str_replace( [ "'", ',', "\n" ], '', (string) $item->get_name() ), 0, 50 ),
					(string) $qty,
					(string) (int) $unit_price,
					(string) (int) round( $total, 0 ),
					'',  // Remark
					'1', // ItemTaxtype 1=應稅
					'',  // IsPassCustoms
				]
			);
		}

		// 運費、費用項目、未對應到項目的退款 — 各列一筆數量 1
		$extra           = [];
		$shipping        = (float) $order->get_shipping_total() - (float) $order->get_total_shipping_refunded();
		$total_refunded -= (float) $order->get_total_shipping_refunded();
		if ( $shipping > 0 ) {
			$extra[] = [ __( 'Shipping', 'moksa-for-woocommerce' ), $shipping ];
		}
		foreach ( $order->get_items( [ 'fee' ] ) as $fee ) {
			$fee_refunded    = (float) $order->get_total_refunded_for_item( $fee->get_id(), 'fee' );
			$total_refunded -= $fee_refunded;
			$fee_total       = (float) $fee->get_total() - $fee_refunded;
			if ( 0.0 !== $fee_total ) {
				$extra[] = [ str_replace( [ "'", ',', "\n" ], '', (string) $fee->get_name() ), $fee_total ];
			}
		}
		if ( round( $total_refunded, 0 ) > 0 ) {
			$extra[] = [ __( 'Refund', 'moksa-for-woocommerce' ), -$total_refunded ];
		}
		foreach ( $extra as [ $name, $amount ] ) {
			$amount = (string) (int) round( $amount, 0 );
			$rows[] = self::format_csv_row(
				[
					$orderno,
					$buyer_id,
					mb_substr( $buyer_name, 0, 30 ),
					mb_substr( $buyer_add, 0, 50 ),
					mb_substr( $buyer_phone, 0, 10 ),
					mb_substr( $buyer_email, 0, 80 ),
					$carrier_t,
					$carrier_1,
					$carrier_2,
					$love_param,
					mb_substr( $name, 0, 50 ),
					'1',
					$amount,
					$amount,
					'',
					'1',
					'',
				]
			);
		}

		return $rows;
	}


	private static function format_csv_row( array $fields ): string {
		$escaped = array_map( static fn( string $f ): string => "'" . $f, $fields );
		return implode( ',', $escaped );
	}
}
