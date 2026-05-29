<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\AmegoInvoice\Operations;

use MoksaWeb\Mowc\Modules\AmegoInvoice\Api\Helper;
use MoksaWeb\Mowc\Modules\AmegoInvoice\Api\Request;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Issue {

	
	public static function run( \WC_Order $order ): array {
		$existing = (string) $order->get_meta( Keys::AMEGO_INVOICE_NUMBER );
		if ( '' !== $existing ) {
			return [ 'ok' => false, 'message' => __( '此訂單已開立發票。', 'mo-ectools' ) ];
		}

		$type = (string) $order->get_meta( Keys::INVOICE_TYPE );
		if ( '' === $type ) {
			return [ 'ok' => false, 'message' => __( '訂單沒設定發票類型。', 'mo-ectools' ) ];
		}

		if ( ! Helper::has_credentials() ) {
			return [ 'ok' => false, 'message' => __( 'Amego 發票憑證未設定。', 'mo-ectools' ) ];
		}

		$total = (int) round( (float) $order->get_total() - (float) $order->get_total_refunded(), 0 );
		if ( $total <= 0 ) {
			$order->update_meta_data( Keys::AMEGO_INVOICE_NUMBER, 'zero' );
			$order->add_order_note( __( '訂單金額為 0 或負，不開立 Amego 發票。', 'mo-ectools' ) );
			$order->save();
			return [ 'ok' => false, 'message' => __( '訂單金額為 0 或負。', 'mo-ectools' ) ];
		}

		$order_id_str = Helper::generate_order_id( $order->get_id() );
		$args         = self::build_args( $order, $order_id_str, $total );

		Helper::log( 'issue request', [
			'order_id'   => $order->get_id(),
			'amego_oid'  => $order_id_str,
			'total'      => $total,
			'item_count' => count( $args['ProductItem'] ),
		] );

		$resp = Request::post( '/json/f0401', $args );

		if ( ! $resp['ok'] ) {
			$msg = sprintf(
				/* translators: 1: error message, 2: code */
				__( 'Amego 發票開立失敗：%1$s（code %2$d）', 'mo-ectools' ),
				$resp['message'],
				$resp['code']
			);
			$order->update_meta_data( Keys::AMEGO_INVOICE_STATUS, 'F' );
			$order->add_order_note( $msg );
			$order->save();
			return [ 'ok' => false, 'message' => $msg ];
		}

		$data       = $resp['data'];
		$invoice_no = (string) ( $data['invoice_number'] ?? '' );
		$random     = (string) ( $data['random_number'] ?? '' );
		$issued_ts  = (int) ( $data['invoice_time'] ?? 0 );
		$barcode    = (string) ( $data['barcode'] ?? '' );
		$qrcode_l   = (string) ( $data['qrcode_left'] ?? '' );
		$qrcode_r   = (string) ( $data['qrcode_right'] ?? '' );

		if ( '' === $invoice_no ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: raw API response text from Amego */
					__( 'Amego 回應 code=0 但沒帶 invoice_number。原始：%s', 'mo-ectools' ),
					$resp['raw']
				)
			);
			$order->save();
			return [ 'ok' => false, 'message' => 'no invoice number returned' ];
		}

		$order->update_meta_data( Keys::AMEGO_INVOICE_NUMBER, $invoice_no );
		$order->update_meta_data( Keys::AMEGO_INVOICE_ORDER_ID, $order_id_str );
		$order->update_meta_data( Keys::AMEGO_INVOICE_RANDOM_NUM, $random );
		$order->update_meta_data( Keys::AMEGO_INVOICE_ISSUED_AT, $issued_ts > 0 ? gmdate( 'Y-m-d H:i:s', $issued_ts ) : current_time( 'mysql' ) );
		$order->update_meta_data( Keys::AMEGO_INVOICE_BARCODE, $barcode );
		$order->update_meta_data( Keys::AMEGO_INVOICE_QRCODE_L, $qrcode_l );
		$order->update_meta_data( Keys::AMEGO_INVOICE_QRCODE_R, $qrcode_r );
		$order->update_meta_data( Keys::AMEGO_INVOICE_STATUS, '99' );
		$order->update_meta_data( Keys::INVOICE_PROVIDER, 'amego' );
		$order->delete_meta_data( Keys::AMEGO_INVOICE_SCHEDULED_AT );

		$order->add_order_note( sprintf(
			/* translators: 1: invoice number, 2: random num */
			__( 'Amego 發票已開立 — 號碼 %1$s 隨機碼 %2$s', 'mo-ectools' ),
			$invoice_no,
			$random
		) );
		$order->save();

		return [ 'ok' => true, 'message' => $invoice_no, 'invoice_number' => $invoice_no ];
	}

	
	private static function build_args( \WC_Order $order, string $order_id_str, int $total ): array {
		$type      = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$carrier   = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$cnum      = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );
		$love_code = (string) $order->get_meta( Keys::INVOICE_LOVE_CODE );

		$buyer_identifier = '0000000000';
		$buyer_name       = trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		if ( '' === $buyer_name ) {
			$buyer_name = '客人';
		}
		$carrier_type = '';
		$carrier_id1  = '';
		$carrier_id2  = '';
		$npoban       = '';

		switch ( $type ) {
			case 'b2c_carrier':
				switch ( $carrier ) {
					case 'mobile':  // 手機條碼 3J0002
						$carrier_type = '3J0002';
						$carrier_id1  = $cnum;
						$carrier_id2  = $cnum;
						break;
					case 'cert':    // 自然人憑證 CQ0001
						$carrier_type = 'CQ0001';
						$carrier_id1  = $cnum;
						$carrier_id2  = $cnum;
						break;
					case 'member':  // 光貿會員載具
						$carrier_type = 'amego';
						$email        = (string) $order->get_billing_email();
						$carrier_id1  = '' !== $email ? $email : ( 'a' . preg_replace( '/[^0-9]/', '', (string) $order->get_billing_phone() ) );
						$carrier_id2  = $carrier_id1;
						break;
					case 'paper':
					default:
						break;
				}
				break;
			case 'b2b':
				$buyer_identifier = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
				$company          = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
				$buyer_name       = '' !== $company ? $company : $buyer_identifier;
				break;
			case 'b2c_donate':
				$npoban = $love_code;
				break;
		}

		// 商品列
		$items          = [];
		$total_taxable  = 0;
		$total_refunded = (float) $order->get_total_refunded();
		foreach ( $order->get_items( [ 'line_item' ] ) as $item ) {
			$item_total    = (float) $item->get_total();
			$item_refunded = (float) $order->get_total_refunded_for_item( $item->get_id(), $item->get_type() );
			$total_refunded -= $item_refunded;
			$line_total     = $item_total - $item_refunded;
			$qty            = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item->get_id(), $item->get_type() );
			if ( $qty <= 0 || 0.0 === $line_total ) {
				continue;
			}
			$unit_price     = round( $line_total / max( $qty, 1 ), 0 );
			$amount         = (int) round( $line_total, 0 );
			$items[]        = [
				'Description' => mb_substr( (string) $item->get_name(), 0, 256 ),
				'Quantity'    => (string) $qty,
				'UnitPrice'   => (string) (int) $unit_price,
				'Amount'      => (string) $amount,
				'Remark'      => '',
				'TaxType'     => 1,
			];
			$total_taxable += $amount;
		}

		// 運費獨立列
		$shipping = (int) round( (float) $order->get_shipping_total() - (float) $order->get_total_shipping_refunded(), 0 );
		if ( $shipping > 0 ) {
			$items[]        = [
				'Description' => __( '運費', 'mo-ectools' ),
				'Quantity'    => '1',
				'UnitPrice'   => (string) $shipping,
				'Amount'      => (string) $shipping,
				'Remark'      => '',
				'TaxType'     => 1,
			];
			$total_taxable += $shipping;
		}

		// 對帳修正（總額與小計不符時補一行）
		if ( $total_taxable !== $total ) {
			$diff           = $total - $total_taxable;
			$items[]        = [
				'Description' => $diff >= 0 ? __( '稅費 / 折扣調整', 'mo-ectools' ) : __( '折扣調整', 'mo-ectools' ),
				'Quantity'    => '1',
				'UnitPrice'   => (string) $diff,
				'Amount'      => (string) $diff,
				'Remark'      => '',
				'TaxType'     => 1,
			];
		}

		// 稅額計算（spec §基本說明 含稅商品金額計算邏輯）
		$is_b2b      = 'b2b' === $type;
		$tax_amount  = $is_b2b ? ( $total - (int) round( $total / 1.05, 0 ) ) : 0;
		$sales_amount = $is_b2b ? ( $total - $tax_amount ) : $total;

		return [
			'OrderId'              => $order_id_str,
			'BuyerIdentifier'      => $buyer_identifier,
			'BuyerName'            => mb_substr( $buyer_name, 0, 60 ),
			'BuyerAddress'         => mb_substr( $order->get_billing_address_1() . $order->get_billing_address_2(), 0, 100 ),
			'BuyerTelephoneNumber' => preg_replace( '/[^0-9]/', '', (string) $order->get_billing_phone() ) ?? '',
			'BuyerEmailAddress'    => (string) $order->get_billing_email(),
			'MainRemark'           => '#' . $order->get_order_number(),
			'CarrierType'          => $carrier_type,
			'CarrierId1'           => $carrier_id1,
			'CarrierId2'           => $carrier_id2,
			'NPOBAN'               => $npoban,
			'ProductItem'          => $items,
			'SalesAmount'          => $sales_amount,
			'FreeTaxSalesAmount'   => 0,
			'ZeroTaxSalesAmount'   => 0,
			'TaxType'              => 1,
			'TaxRate'              => '0.05',
			'TaxAmount'            => $tax_amount,
			'TotalAmount'          => $total,
		];
	}
}
