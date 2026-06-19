<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EzpayInvoice\Operations;

use MoksaWeb\Mowc\Modules\EzpayInvoice\Api\Helper;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Issue {


	public static function run( \WC_Order $order ): array {
		// idempotent — 已開過就不重開
		$existing = (string) $order->get_meta( Keys::EZPAY_INVOICE_NUMBER );
		if ( '' !== $existing ) {
			return [
				'ok'      => false,
				'message' => __( '此訂單已開立發票，不可重複。', 'mo-ectools' ),
			];
		}

		// 沒選發票類型就不開（顧客結帳沒填）
		$type = (string) $order->get_meta( Keys::INVOICE_TYPE );
		if ( '' === $type ) {
			return [
				'ok'      => false,
				'message' => __( '訂單沒設定發票類型。', 'mo-ectools' ),
			];
		}

		$args = self::build_args( $order );

		// 0 元跟負額 — 不送 API（同 RY pattern）
		if ( 0 === (int) $args['TotalAmt'] ) {
			$order->update_meta_data( Keys::EZPAY_INVOICE_NUMBER, 'zero' );
			$order->add_order_note( __( '訂單金額為 0，不開立 ezPay 發票。', 'mo-ectools' ) );
			$order->save();
			return [
				'ok'             => true,
				'message'        => __( '訂單金額為 0，未開立。', 'mo-ectools' ),
				'invoice_number' => 'zero',
			];
		}
		if ( (int) $args['TotalAmt'] < 0 ) {
			$order->update_meta_data( Keys::EZPAY_INVOICE_NUMBER, 'negative' );
			$order->add_order_note( __( '訂單金額為負，無法開立 ezPay 發票（需走折讓單）。', 'mo-ectools' ) );
			$order->save();
			return [
				'ok'             => false,
				'message'        => __( '訂單金額為負，無法開立。', 'mo-ectools' ),
				'invoice_number' => 'negative',
			];
		}

		// ItemName/Count/Amt 是 array — 送出時要 implode '|'
		foreach ( [ 'ItemName', 'ItemCount', 'ItemPrice', 'ItemUnit', 'ItemAmt' ] as $k ) {
			if ( isset( $args[ $k ] ) && is_array( $args[ $k ] ) ) {
				$args[ $k ] = implode( '|', $args[ $k ] );
			}
		}

		Helper::log(
			'issue request',
			[
				'order_id'          => $order->get_id(),
				'merchant_order_no' => $args['MerchantOrderNo'],
			]
		);

		$result = Helper::post( '/Api/invoice_issue', $args );

		if ( ! $result['ok'] ) {
			$msg = sprintf(
				/* translators: 1: error message, 2: status code */
				__( 'ezPay 發票開立失敗：%1$s（%2$s）', 'mo-ectools' ),
				$result['message'] ?? '',
				$result['status'] ?? ''
			);
			$order->add_order_note( $msg );
			$order->save();
			return [
				'ok'      => false,
				'message' => $msg,
			];
		}

		$data       = $result['data'] ?? [];
		$invoice_no = (string) ( $data['InvoiceNumber'] ?? '' );
		$random     = (string) ( $data['RandomNum'] ?? '' );
		$created    = (string) ( $data['CreateTime'] ?? '' );
		$trans_no   = (string) ( $data['InvoiceTransNo'] ?? '' );

		if ( '' === $invoice_no ) {
			$order->add_order_note( __( 'ezPay 回應 SUCCESS 但沒帶 InvoiceNumber，請檢查 ezPay 後台。', 'mo-ectools' ) );
			$order->save();
			return [
				'ok'      => false,
				'message' => 'no invoice number returned',
			];
		}

		$order->update_meta_data( Keys::EZPAY_INVOICE_NUMBER, $invoice_no );
		$order->update_meta_data( Keys::EZPAY_RANDOM_NUM, $random );
		$order->update_meta_data( Keys::EZPAY_INVOICE_TRANS_NO, $trans_no );
		$order->update_meta_data( Keys::EZPAY_MERCHANT_ORDER_NO, (string) $args['MerchantOrderNo'] );
		$order->update_meta_data( Keys::EZPAY_CREATE_TIME, $created ?: current_time( 'mysql' ) );
		// 開立後清掉 scheduled_at（如果是延後開立路徑進來的）
		$order->delete_meta_data( Keys::EZPAY_SCHEDULED_AT );

		$order->add_order_note(
			sprintf(
			/* translators: 1: invoice number, 2: random number, 3: create time */
				__( 'ezPay 發票已開立 — 號碼 %1$s 隨機碼 %2$s（%3$s）', 'mo-ectools' ),
				$invoice_no,
				$random,
				$created
			)
		);
		$order->save();

		return [
			'ok'             => true,
			'message'        => $invoice_no,
			'invoice_number' => $invoice_no,
		];
	}

	private static function build_args( \WC_Order $order ): array {
		$now = new \DateTime( 'now', new \DateTimeZone( 'Asia/Taipei' ) );

		$total = (int) round( (float) $order->get_total() - (float) $order->get_total_refunded(), 0 );

		$buyer_country = WC()->countries->countries[ $order->get_billing_country() ] ?? $order->get_billing_country();
		$buyer_states  = WC()->countries->get_states( $order->get_billing_country() );
		$buyer_state   = $buyer_states[ $order->get_billing_state() ] ?? $order->get_billing_state();
		$buyer_address = $buyer_country . $buyer_state . $order->get_billing_city() . $order->get_billing_address_1() . $order->get_billing_address_2();
		$buyer_name    = trim( $order->get_billing_last_name() . $order->get_billing_first_name() );

		$args = [
			'RespondType'     => 'JSON',
			'Version'         => '1.5',
			'TimeStamp'       => (string) $now->getTimestamp(),
			'MerchantOrderNo' => Helper::generate_merchant_order_no( $order->get_id() ),
			'Status'          => '1',          // 1 = 立即開立
			'Category'        => 'B2C',        // 預設個人，b2b 下面覆蓋
			'CarrierType'     => '',
			'CarrierNum'      => '',
			'LoveCode'        => '',
			'PrintFlag'       => 'N',
			'Comment'         => '#' . $order->get_order_number(),

			'TaxType'         => '1',
			'TaxRate'         => '5',
			'TotalAmt'        => $total,
			'Amt'             => (int) round( $total / 1.05 ),
			'TaxAmt'          => $total - (int) round( $total / 1.05 ),

			'ItemName'        => [],
			'ItemCount'       => [],
			'ItemPrice'       => [],
			'ItemUnit'        => [],
			'ItemAmt'         => [],

			'BuyerName'       => '' !== $buyer_name ? $buyer_name : '',
			'BuyerAddress'    => $buyer_address,
			'BuyerEmail'      => $order->get_billing_email(),
		];

		// 依 INVOICE_TYPE 設 Category / Carrier / LoveCode
		$type    = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$carrier = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$cnum    = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );

		switch ( $type ) {
			case 'b2c_carrier':
				switch ( $carrier ) {
					case 'member':  // ezPay 平台會員載具
						$args['CarrierType'] = '2';
						$args['CarrierNum']  = wp_hash( $order->get_billing_email() );
						break;
					case 'cert':    // 自然人憑證
						$args['CarrierType'] = '1';
						$args['CarrierNum']  = $cnum;
						break;
					case 'mobile':  // 手機條碼
						$args['CarrierType'] = '0';
						$args['CarrierNum']  = $cnum;
						break;
					case 'paper':
					default:
						$args['PrintFlag'] = 'Y';
						break;
				}
				break;
			case 'b2b':
				$args['Category']  = 'B2B';
				$args['PrintFlag'] = 'Y';
				$args['BuyerUBN']  = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
				$buyer_company     = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
				$args['BuyerName'] = '' !== $buyer_company ? $buyer_company : $args['BuyerUBN'];
				break;
			case 'b2c_donate':
				$args['LoveCode'] = (string) $order->get_meta( Keys::INVOICE_LOVE_CODE );
				break;
		}

		// 商品逐項
		$total_refunded = (float) $order->get_total_refunded();
		foreach ( $order->get_items( [ 'line_item' ] ) as $item ) {
			$item_total      = (float) $item->get_total();
			$item_refunded   = (float) $order->get_total_refunded_for_item( $item->get_id(), $item->get_type() );
			$total_refunded -= $item_refunded;
			$line_total      = $item_total - $item_refunded;
			$qty             = $item->get_quantity() + $order->get_qty_refunded_for_item( $item->get_id(), $item->get_type() );
			if ( 0.0 === $line_total && 0 === $qty ) {
				continue;
			}
			$args['ItemName'][]  = mb_substr( str_replace( '|', '', (string) $item->get_name() ), 0, 30 );
			$args['ItemCount'][] = $qty <= 0 ? 1 : (string) $qty;
			$args['ItemAmt'][]   = $line_total;
		}

		// 運費
		$shipping_fee    = (float) $order->get_shipping_total() - (float) $order->get_total_shipping_refunded();
		$total_refunded -= (float) $order->get_total_shipping_refunded();
		if ( 0.0 !== $shipping_fee ) {
			$args['ItemName'][]  = __( '運費', 'mo-ectools' );
			$args['ItemCount'][] = '1';
			$args['ItemAmt'][]   = $shipping_fee;
		}

		// 額外退款（部分退款超過商品/運費的部份）
		if ( 0.0 !== $total_refunded ) {
			$args['ItemName'][]  = __( '退款', 'mo-ectools' );
			$args['ItemCount'][] = '1';
			$args['ItemAmt'][]   = -$total_refunded;
		}

		// 金額重算 + ItemPrice 反推
		foreach ( $args['ItemName'] as $i => $name ) {
			$amt   = (float) $args['ItemAmt'][ $i ];
			$count = (float) $args['ItemCount'][ $i ];
			if ( 'B2B' === $args['Category'] ) {
				$amt = $amt / 1.05;  // B2B 含稅金額拆分
			}
			$price                   = $count > 0 ? round( $amt / $count, 6 ) : 0;
			$args['ItemPrice'][ $i ] = (string) $price;
			$args['ItemAmt'][ $i ]   = (string) (int) round( $count * $price, 0 );
			$args['ItemCount'][ $i ] = (string) $count;
			$args['ItemUnit'][ $i ]  = __( '件', 'mo-ectools' );
		}

		$args['Comment'] = mb_substr( $args['Comment'], 0, 100 );

		return $args;
	}
}
