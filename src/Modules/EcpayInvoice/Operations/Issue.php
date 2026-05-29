<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayInvoice\Operations;

use MoksaWeb\Mowc\Modules\EcpayInvoice\Api\Helper;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Issue {

	
	public static function run( \WC_Order $order ): array {
		// 已開過就不重開（依官方 spec — Issue 不冪等，重複會錯）
		if ( $order->get_meta( Keys::ECPAY_INVOICE_NUMBER ) ) {
			return [ 'ok' => false, 'message' => __( '此訂單已開立發票。', 'mo-ectools' ) ];
		}

		$relate_no = (string) $order->get_meta( Keys::ECPAY_INVOICE_RELATE_NUMBER );
		if ( '' === $relate_no ) {
			$relate_no = Helper::generate_relate_number( $order->get_id() );
			$order->update_meta_data( Keys::ECPAY_INVOICE_RELATE_NUMBER, $relate_no );
		}

		$invoice_type   = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$buyer_ubn      = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
		$buyer_name     = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
		$carrier_type   = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$carrier_num    = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );
		$love_code      = (string) $order->get_meta( Keys::INVOICE_LOVE_CODE );
		$is_b2b         = 'b2b' === $invoice_type && '' !== $buyer_ubn;
		$is_donate      = 'b2c_donate' === $invoice_type && '' !== $love_code;

		$customer_name = $is_b2b ? $buyer_name : trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		if ( '' === $customer_name ) {
			$customer_name = '消費者';
		}

		$customer_addr = trim( implode( '', [
			$order->get_billing_state(),
			$order->get_billing_city(),
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
		] ) );

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
			'Print'              => $is_b2b ? '1' : '0',
			'Donation'           => $is_donate ? '1' : '0',
			'LoveCode'           => $is_donate ? $love_code : '',
			'CarrierType'        => $is_b2b || $is_donate ? '' : self::carrier_type_code( $carrier_type ),
			'CarrierNum'         => $is_b2b || $is_donate ? '' : $carrier_num,
			'TaxType'            => '1',
			'SalesAmount'        => $amount,
			'InvoiceRemark'      => '',
			'Items'              => $items,
			'InvType'            => '07',
			'vat'                => '1',
		];

		$result = Helper::post( '/B2CInvoice/Issue', $data );
		if ( ! $result['ok'] ) {
			$order->add_order_note( sprintf(
				/* translators: %s: error message */
				__( '綠界發票開立失敗：%s', 'mo-ectools' ),
				$result['message']
			) );
			return [ 'ok' => false, 'message' => $result['message'] ];
		}

		$resp = $result['data'] ?? [];
		$inv  = (string) ( $resp['InvoiceNo'] ?? '' );
		$rand = (string) ( $resp['RandomNumber'] ?? '' );

		$order->update_meta_data( Keys::ECPAY_INVOICE_NUMBER, $inv );
		$order->update_meta_data( Keys::ECPAY_INVOICE_RANDOM, $rand );
		$order->update_meta_data( Keys::ECPAY_INVOICE_ISSUED_AT, current_time( 'mysql' ) );
		$order->update_meta_data( Keys::ECPAY_INVOICE_TAX_TYPE, '1' );
		$order->update_meta_data( Keys::INVOICE_PROVIDER, 'ecpay' );
		$order->add_order_note( sprintf(
			/* translators: 1: invoice number, 2: random */
			__( '綠界發票已開立 — 號碼 %1$s 隨機碼 %2$s', 'mo-ectools' ),
			$inv,
			$rand
		) );
		$order->save();

		return [ 'ok' => true, 'message' => $result['message'], 'invoice_no' => $inv ];
	}

	private static function carrier_type_code( string $internal ): string {
		// internal 我們用 'member' / 'cert' / 'mobile' 或數字
		return match ( $internal ) {
			'mobile', '3'     => '3',
			'cert', '2'       => '2',
			'member', '1', '' => '1',
			default           => '1',
		};
	}

	private static function build_items( \WC_Order $order, int $total ): array {
		$items = [];
		$index = 1;
		$line_sum = 0;
		foreach ( $order->get_items() as $item ) {
			$qty   = (float) $item->get_quantity();
			$total_amt = (int) round( (float) $item->get_total() + (float) $item->get_total_tax() );
			$unit  = $qty > 0 ? (int) round( $total_amt / $qty ) : 0;
			$line_sum += $total_amt;
			$items[] = [
				'ItemSeq'     => $index++,
				'ItemName'    => mb_substr( $item->get_name(), 0, 100 ),
				'ItemCount'   => (int) $qty,
				'ItemWord'    => '批',
				'ItemPrice'   => $unit,
				'ItemTaxType' => '1',
				'ItemAmount'  => $total_amt,
			];
		}

		// 運費 + 手續費 等 fee items
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

		// 補差額 — line_sum vs $total（捨入差等問題）
		if ( $line_sum !== $total ) {
			$diff      = $total - $line_sum;
			$items[]   = [
				'ItemSeq'     => $index++,
				'ItemName'    => __( '其他', 'mo-ectools' ),
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
