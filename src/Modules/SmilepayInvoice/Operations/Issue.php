<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayInvoice\Operations;

use MoksaWeb\Mowc\Modules\SmilepayInvoice\Api\Helper;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Issue {

	
	public static function run( \WC_Order $order ): array {
		// idempotent
		$existing = (string) $order->get_meta( Keys::SMILEPAY_INVOICE_NUMBER );
		if ( '' !== $existing ) {
			return [ 'ok' => false, 'message' => __( '此訂單已開立發票，不可重複。', 'mo-ectools' ) ];
		}

		$type = (string) $order->get_meta( Keys::INVOICE_TYPE );
		if ( '' === $type ) {
			return [ 'ok' => false, 'message' => __( '訂單沒設定發票類型。', 'mo-ectools' ) ];
		}

		$args = self::build_args( $order );

		if ( 0 === (int) $args['AllAmount'] ) {
			$order->update_meta_data( Keys::SMILEPAY_INVOICE_NUMBER, 'zero' );
			$order->add_order_note( __( '訂單金額為 0，不開立 SmilePay 發票。', 'mo-ectools' ) );
			$order->save();
			return [ 'ok' => true, 'message' => __( '訂單金額為 0，未開立。', 'mo-ectools' ), 'invoice_number' => 'zero' ];
		}
		if ( (int) $args['AllAmount'] < 0 ) {
			$order->update_meta_data( Keys::SMILEPAY_INVOICE_NUMBER, 'negative' );
			$order->add_order_note( __( '訂單金額為負，無法開立 SmilePay 發票。', 'mo-ectools' ) );
			$order->save();
			return [ 'ok' => false, 'message' => __( '訂單金額為負，無法開立。', 'mo-ectools' ), 'invoice_number' => 'negative' ];
		}

		// implode array fields
		foreach ( [ 'Description', 'Quantity', 'UnitPrice', 'Unit', 'Amount' ] as $k ) {
			if ( isset( $args[ $k ] ) && is_array( $args[ $k ] ) ) {
				$args[ $k ] = implode( '|', $args[ $k ] );
			}
		}

		Helper::log( 'issue request', [ 'order_id' => $order->get_id(), 'order_id_str' => $args['orderid'] ] );

		$result = Helper::post( Helper::PATH_ISSUE, $args );

		if ( ! $result['ok'] ) {
			$msg = sprintf(
				/* translators: 1: error message, 2: status code */
				__( 'SmilePay 發票開立失敗：%1$s（%2$s）', 'mo-ectools' ),
				$result['message'] ?? '',
				$result['status'] ?? ''
			);
			$order->add_order_note( $msg );
			$order->save();
			return [ 'ok' => false, 'message' => $msg ];
		}

		$data = $result['data'] ?? [];
		$invoice_no = (string) ( $data['InvoiceNumber'] ?? '' );
		$random     = (string) ( $data['RandomNumber'] ?? '' );
		$inv_date   = (string) ( $data['InvoiceDate'] ?? '' );
		$inv_time   = (string) ( $data['InvoiceTime'] ?? '' );

		if ( '' === $invoice_no ) {
			$order->add_order_note( __( 'SmilePay 回應 Status=0 但沒帶 InvoiceNumber，請檢查 SmilePay 後台。', 'mo-ectools' ) );
			$order->save();
			return [ 'ok' => false, 'message' => 'no invoice number returned' ];
		}

		// 組 issue_at = InvoiceDate + InvoiceTime
		$issue_at = '';
		if ( '' !== $inv_date ) {
			try {
				$dt = new \DateTime( str_replace( '/', '-', $inv_date ) );
				if ( '' !== $inv_time ) {
					$tparts = explode( ':', $inv_time );
					if ( count( $tparts ) === 3 ) {
						$dt->setTime( (int) $tparts[0], (int) $tparts[1], (int) $tparts[2] );
					}
				}
				$issue_at = $dt->format( 'Y-m-d H:i:s' );
			} catch ( \Throwable $e ) {
				$issue_at = current_time( 'mysql' );
			}
		} else {
			$issue_at = current_time( 'mysql' );
		}

		$order->update_meta_data( Keys::SMILEPAY_INVOICE_NUMBER, $invoice_no );
		$order->update_meta_data( Keys::SMILEPAY_INVOICE_RANDOM, $random );
		$order->update_meta_data( Keys::SMILEPAY_INVOICE_DATE, $issue_at );
		$order->update_meta_data( Keys::SMILEPAY_INVOICE_ORDER_ID, (string) $args['orderid'] );
		$order->delete_meta_data( Keys::SMILEPAY_INVOICE_SCHEDULED_AT );

		$order->add_order_note( sprintf(
			/* translators: 1: invoice number, 2: random number, 3: create time */
			__( 'SmilePay 發票已開立 — 號碼 %1$s 隨機碼 %2$s（%3$s）', 'mo-ectools' ),
			$invoice_no,
			$random,
			$issue_at
		) );
		$order->save();

		return [ 'ok' => true, 'message' => $invoice_no, 'invoice_number' => $invoice_no ];
	}

	private static function build_args( \WC_Order $order ): array {
		$now = new \DateTime( 'now', new \DateTimeZone( 'Asia/Taipei' ) );

		$buyer_country = WC()->countries->countries[ $order->get_billing_country() ] ?? $order->get_billing_country();
		$buyer_states  = WC()->countries->get_states( $order->get_billing_country() );
		$buyer_state   = $buyer_states[ $order->get_billing_state() ] ?? $order->get_billing_state();
		$buyer_address = $buyer_country . $buyer_state . $order->get_billing_city() . $order->get_billing_address_1() . $order->get_billing_address_2();
		$buyer_name    = trim( $order->get_billing_last_name() . $order->get_billing_first_name() );

		$total = (int) round( (float) $order->get_total() - (float) $order->get_total_refunded(), 0 );

		$args = [
			'Grvc'               => Helper::grvc(),
			'Verify_key'         => Helper::verify_key(),
			'InvoiceDate'        => $now->format( 'Y/m/d' ),
			'InvoiceTime'        => $now->format( 'H:i:s' ),
			'TrackSystemID'      => Helper::track_system_id(),
			'Intype'             => '07',  // 07 = 一般稅額
			'TaxType'            => '1',
			'DonateMark'         => '0',
			'LoveKey'            => '',
			'orderid'            => Helper::generate_order_id( $order->get_id() ),
			'MainRemark'         => '',
			'Certificate_Remark' => '#' . $order->get_order_number(),

			'Description'        => [],
			'Quantity'           => [],
			'UnitPrice'          => [],
			'Unit'                => [],
			'Amount'              => [],
			'AllAmount'           => $total,

			'Name'                => $buyer_name,
			'Address'             => $buyer_address,
			'Phone'               => $order->get_billing_phone(),
			'Email'               => $order->get_billing_email(),
		];

		$type    = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$carrier = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$cnum    = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );

		switch ( $type ) {
			case 'b2c_carrier':
				switch ( $carrier ) {
					case 'member':  // SmilePay 會員載具
						$args['CarrierType'] = 'EJ0113';
						break;
					case 'cert':    // 自然人憑證
						$args['CarrierType'] = 'CQ0001';
						$args['CarrierID']   = $cnum;
						break;
					case 'mobile':  // 手機條碼
						$args['CarrierType'] = '3J0002';
						$args['CarrierID']   = $cnum;
						break;
					case 'paper':
					default:
						break;
				}
				break;
			case 'b2b':
				$args['Buyer_id'] = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
				$args['UnitTAX']  = 'Y';
				$buyer_company    = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
				if ( '' !== $buyer_company ) {
					$args['CompanyName'] = $buyer_company;
				}
				break;
			case 'b2c_donate':
				$args['DonateMark'] = '1';
				$args['LoveKey']    = (string) $order->get_meta( Keys::INVOICE_LOVE_CODE );
				break;
		}

		// 商品逐項
		$total_refunded = (float) $order->get_total_refunded();
		foreach ( $order->get_items( [ 'line_item' ] ) as $item ) {
			$item_total    = (float) $item->get_total();
			$item_refunded = (float) $order->get_total_refunded_for_item( $item->get_id(), $item->get_type() );
			$total_refunded -= $item_refunded;
			$line_total     = $item_total - $item_refunded;
			$qty            = $item->get_quantity() + $order->get_qty_refunded_for_item( $item->get_id(), $item->get_type() );
			if ( 0.0 === $line_total && 0 === $qty ) {
				continue;
			}
			$args['Description'][] = mb_substr( str_replace( '|', '', (string) $item->get_name() ), 0, 80 );
			$args['Quantity'][]    = $qty <= 0 ? 1 : (string) $qty;
			$args['Amount'][]      = $line_total;
		}

		// 運費
		$shipping_fee = (float) $order->get_shipping_total() - (float) $order->get_total_shipping_refunded();
		$total_refunded -= (float) $order->get_total_shipping_refunded();
		if ( 0.0 !== $shipping_fee ) {
			$args['Description'][] = __( '運費', 'mo-ectools' );
			$args['Quantity'][]    = '1';
			$args['Amount'][]      = $shipping_fee;
		}

		if ( 0.0 !== $total_refunded ) {
			$args['Description'][] = __( '退款', 'mo-ectools' );
			$args['Quantity'][]    = '1';
			$args['Amount'][]      = -$total_refunded;
		}

		// 重算 UnitPrice / Amount
		foreach ( $args['Description'] as $i => $name ) {
			$amt   = (float) $args['Amount'][ $i ];
			$count = (float) $args['Quantity'][ $i ];
			$price = $count > 0 ? round( $amt / $count, 6 ) : 0;
			$args['UnitPrice'][ $i ] = (string) $price;
			$args['Amount'][ $i ]    = (string) (int) round( $count * $price, 0 );
			$args['Quantity'][ $i ]  = (string) $count;
			$args['Unit'][ $i ]      = __( '件', 'mo-ectools' );
		}

		$args['MainRemark']         = mb_substr( $args['MainRemark'], 0, 100 );
		$args['Certificate_Remark'] = mb_substr( $args['Certificate_Remark'], 0, 30 );

		return $args;
	}
}
