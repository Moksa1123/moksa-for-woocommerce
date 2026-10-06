<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayInvoice\Operations;

use Moksafowo\Modules\EcpayInvoice\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Issue {


	public static function run( \WC_Order $order ): array {
		$existing = (string) $order->get_meta( Keys::ECPAY_INVOICE_NUMBER );
		$voided   = (string) $order->get_meta( Keys::ECPAY_INVOICE_INVALID_AT );

		if ( '' !== $existing && '' === $voided ) {
			return [
				'ok'      => false,
				'message' => __( 'This order already has an invoice.', 'moksa-for-woocommerce' ),
			];
		}

		// Re-issue after void: clear old meta and generate a new RelateNumber (old number cannot be reused per ECPay spec).
		if ( '' !== $existing && '' !== $voided ) {
			$order->delete_meta_data( Keys::ECPAY_INVOICE_NUMBER );
			$order->delete_meta_data( Keys::ECPAY_INVOICE_RANDOM );
			$order->delete_meta_data( Keys::ECPAY_INVOICE_ISSUED_AT );
			$order->delete_meta_data( Keys::ECPAY_INVOICE_INVALID_AT );
			$order->delete_meta_data( Keys::ECPAY_INVOICE_RELATE_NUMBER );
			$order->save();
		}

		$relate_no = (string) $order->get_meta( Keys::ECPAY_INVOICE_RELATE_NUMBER );
		if ( '' === $relate_no ) {
			$relate_no = Helper::generate_relate_number( $order->get_id() );
			$order->update_meta_data( Keys::ECPAY_INVOICE_RELATE_NUMBER, $relate_no );
		}

		$invoice_type = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$buyer_ubn    = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
		$buyer_name   = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
		$carrier_type = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$carrier_num  = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );
		$love_code    = (string) $order->get_meta( Keys::INVOICE_LOVE_CODE );
		$is_b2b       = 'b2b' === $invoice_type && '' !== $buyer_ubn;
		$is_donate    = 'b2c_donate' === $invoice_type && '' !== $love_code;
		// 紙本是載具類型的一個選項；不分開處理會落到預設的會員載具，顧客拿不到紙本。
		$is_paper = ! $is_b2b && ! $is_donate && 'paper' === $carrier_type;
		$print    = $is_b2b || $is_paper;

		$customer_name = $is_b2b ? $buyer_name : trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		if ( '' === $customer_name ) {
			$customer_name = '消費者';
		}

		$customer_addr = trim(
			implode(
				'',
				[
					$order->get_billing_state(),
					$order->get_billing_city(),
					$order->get_billing_address_1(),
					$order->get_billing_address_2(),
				]
			)
		);
		// 超商單可能隱藏了帳單地址，但列印發票時綠界要求買受人地址必填。
		// 區塊結帳會把鄉鎮同步進 city，只看整串是否為空會送出只有「中正區」的地址。
		$store_addr = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ADDRESS );
		if ( $print && '' === trim( $order->get_billing_address_1() ) && '' !== $store_addr ) {
			$customer_addr = $store_addr;
		}

		$amount = (int) round( (float) $order->get_total() );
		$items  = self::build_items( $order, $amount );

		$data = [
			'MerchantID'         => Helper::merchant_id(),
			'RelateNumber'       => $relate_no,
			'CustomerID'         => '',
			'CustomerIdentifier' => $is_b2b ? $buyer_ubn : '',
			'CustomerName'       => mb_substr( $customer_name, 0, 60 ),
			'CustomerAddr'       => mb_substr( $customer_addr, 0, 100 ),
			'CustomerPhone'      => $order->get_billing_phone(),
			'CustomerEmail'      => $order->get_billing_email(),
			'Print'              => $print ? '1' : '0',
			'Donation'           => $is_donate ? '1' : '0',
			'LoveCode'           => $is_donate ? $love_code : '',
			'CarrierType'        => $print || $is_donate ? '' : self::carrier_type_code( $carrier_type ),
			'CarrierNum'         => $print || $is_donate ? '' : $carrier_num,
			'TaxType'            => '1',
			'SalesAmount'        => $amount,
			'InvoiceRemark'      => '',
			'Items'              => $items,
			'InvType'            => '07',
			'vat'                => '1',
		];

		$result = Helper::post( '/B2CInvoice/Issue', $data );
		if ( ! $result['ok'] ) {
			$order->add_order_note(
				sprintf(
				/* translators: %s: error message */
					__( 'The ECPay invoice could not be issued: %s', 'moksa-for-woocommerce' ),
					$result['message']
				)
			);
			return [
				'ok'      => false,
				'message' => $result['message'],
			];
		}

		$resp = $result['data'] ?? [];
		$inv  = (string) ( $resp['InvoiceNo'] ?? '' );
		$rand = (string) ( $resp['RandomNumber'] ?? '' );

		$order->update_meta_data( Keys::ECPAY_INVOICE_NUMBER, $inv );
		$order->update_meta_data( Keys::ECPAY_INVOICE_RANDOM, $rand );
		$order->update_meta_data( Keys::ECPAY_INVOICE_ISSUED_AT, current_time( 'mysql' ) );
		$order->update_meta_data( Keys::ECPAY_INVOICE_TAX_TYPE, '1' );
		$order->update_meta_data( Keys::INVOICE_PROVIDER, 'ecpay' );
		$order->add_order_note(
			sprintf(
			/* translators: 1: invoice number, 2: random */
				__( 'ECPay invoice issued — number %1$s, random code %2$s', 'moksa-for-woocommerce' ),
				$inv,
				$rand
			)
		);
		$order->save();

		return [
			'ok'         => true,
			'message'    => $result['message'],
			'invoice_no' => $inv,
		];
	}

	private static function carrier_type_code( string $internal ): string {
		return match ( $internal ) {
			'mobile', '3'     => '3',
			'cert', '2'       => '2',
			'member', '1', '' => '1',
			default           => '1',
		};
	}

	private static function build_items( \WC_Order $order, int $total ): array {
		$items    = [];
		$index    = 1;
		$line_sum = 0;
		foreach ( $order->get_items() as $item ) {
			$qty       = (float) $item->get_quantity();
			$total_amt = (int) round( (float) $item->get_total() + (float) $item->get_total_tax() );
			$unit      = $qty > 0 ? (int) round( $total_amt / $qty ) : 0;
			$line_sum += $total_amt;
			$items[]   = [
				'ItemSeq'     => $index++,
				'ItemName'    => mb_substr( $item->get_name(), 0, 100 ),
				'ItemCount'   => (int) $qty,
				'ItemWord'    => '批',
				'ItemPrice'   => $unit,
				'ItemTaxType' => '1',
				'ItemAmount'  => $total_amt,
			];
		}

		foreach ( $order->get_shipping_methods() as $shipping ) {
			$amt = (int) round( (float) $shipping->get_total() + (float) $shipping->get_total_tax() );
			if ( 0 === $amt ) {
				continue;
			}
			$line_sum += $amt;
			$items[]   = [
				'ItemSeq'     => $index++,
				'ItemName'    => $shipping->get_name(),
				'ItemCount'   => 1,
				'ItemWord'    => '式',
				'ItemPrice'   => $amt,
				'ItemTaxType' => '1',
				'ItemAmount'  => $amt,
			];
		}

		if ( $line_sum !== $total ) {
			$diff    = $total - $line_sum;
			$items[] = [
				'ItemSeq'     => $index++,
				'ItemName'    => __( 'Other', 'moksa-for-woocommerce' ),
				'ItemCount'   => 1,
				'ItemWord'    => '式',
				'ItemPrice'   => $diff,
				'ItemTaxType' => '1',
				'ItemAmount'  => $diff,
			];
		}
		return $items;
	}
}
