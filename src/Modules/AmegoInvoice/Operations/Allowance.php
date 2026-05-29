<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\AmegoInvoice\Operations;

use MoksaWeb\Mowc\Modules\AmegoInvoice\Api\Helper;
use MoksaWeb\Mowc\Modules\AmegoInvoice\Api\Request;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;


final class Allowance {

	
	public static function run( \WC_Order $order, int $amount ): array {
		$inv = (string) $order->get_meta( Keys::AMEGO_INVOICE_NUMBER );
		if ( '' === $inv || 'zero' === $inv || 'negative' === $inv ) {
			return [ 'ok' => false, 'message' => __( '此訂單沒有可折讓的 Amego 發票。', 'mo-ectools' ) ];
		}
		if ( $amount <= 0 ) {
			return [ 'ok' => false, 'message' => __( '折讓金額需大於 0。', 'mo-ectools' ) ];
		}

		$item_name = '其他';
		foreach ( $order->get_items() as $i ) {
			$item_name = (string) $i->get_name();
			break;
		}

		$allowance_number = 'A' . substr( (string) $order->get_id(), 0, 6 ) . substr( (string) time(), -6 );  // ≤16 chars
		$args = [
			'AllowanceNumber'       => $allowance_number,
			'OriginalInvoiceNumber' => $inv,
			// 2025/1/1 起 AllowanceType=1（買方申請）已禁用，必為 2（賣方開立折讓通知單）
			'AllowanceType'     => '2',
			'AllowanceDate'     => gmdate( 'Ymd', current_time( 'timestamp' ) ),  // Amego spec: YYYYMMDD
			'BuyerIdentifier'   => (string) ( $order->get_meta( Keys::INVOICE_BUYER_UBN ) ?: '0000000000' ),
			'BuyerName'         => trim( $order->get_billing_last_name() . $order->get_billing_first_name() ) ?: '客人',
			'NotifyMail'        => (string) $order->get_billing_email(),
			'ProductItem'       => [ [
				'OriginalDescription' => mb_substr( $item_name, 0, 256 ),
				'Description' => mb_substr( $item_name, 0, 256 ),
				'Quantity'    => '1',
				'UnitPrice'   => (string) $amount,
				'Amount'      => (string) $amount,
				'Remark'      => '',
				'TaxType'     => 1,
			] ],
			'TaxAmount'         => 0,
			'TotalAmount'       => $amount,
		];

		Helper::log( 'allowance request', [
			'order_id'   => $order->get_id(),
			'invoice_no' => $inv,
			'amount'     => $amount,
		] );

		// g0401 spec：data 必為陣列（即使單張）— 同 f0501 模式
		$resp = Request::post( '/json/g0401', [ $args ] );
		if ( ! $resp['ok'] ) {
			$order->add_order_note( sprintf(
				/* translators: %s: error */
				__( 'Amego 折讓失敗：%s', 'mo-ectools' ),
				$resp['message']
			) );
			$order->save();
			return [ 'ok' => false, 'message' => $resp['message'] ];
		}

		$data         = $resp['data'];
		$allowance_no = (string) ( $data['allowance_number'] ?? $args['AllowanceNumber'] );

		$order->update_meta_data( Keys::AMEGO_INVOICE_ALLOWANCE_NO, $allowance_no );
		$order->update_meta_data( Keys::AMEGO_INVOICE_ALLOWANCE_AMT, (string) $amount );
		$order->add_order_note( sprintf(
			/* translators: 1: allowance no, 2: amount */
			__( 'Amego 折讓單 %1$s 開立成功（金額 NT$%2$d）', 'mo-ectools' ),
			$allowance_no,
			$amount
		) );
		$order->save();

		return [ 'ok' => true, 'message' => 'OK', 'allowance_no' => $allowance_no ];
	}
}
