<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayInvoice\Operations;

use MoksaWeb\Mowc\Modules\EcpayInvoice\Api\Helper;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Allowance {

	
	public static function run( \WC_Order $order, int $amount ): array {
		$inv = (string) $order->get_meta( Keys::ECPAY_INVOICE_NUMBER );
		if ( '' === $inv ) {
			return [ 'ok' => false, 'message' => __( '此訂單沒有發票，無法折讓。', 'mo-ectools' ) ];
		}
		if ( $amount <= 0 ) {
			return [ 'ok' => false, 'message' => __( '折讓金額需大於 0。', 'mo-ectools' ) ];
		}

		// 取訂單第一個 item 當代表（簡化）— 實際情境若要逐項折讓需擴充
		$item_name = '其他';
		foreach ( $order->get_items() as $i ) {
			$item_name = $i->get_name();
			break;
		}

		$data = [
			'MerchantID'    => Helper::merchant_id(),
			'InvoiceNo'     => $inv,
			'InvoiceDate'   => (string) ( $order->get_meta( Keys::ECPAY_INVOICE_ISSUED_AT ) ?: current_time( 'Y-m-d' ) ),
			'AllowanceNotify' => 'E',
			'CustomerName'  => trim( $order->get_billing_last_name() . $order->get_billing_first_name() ),
			'NotifyMail'    => $order->get_billing_email(),
			'AllowanceAmount' => $amount,
			'Items'         => [ [
				'ItemSeq'    => 1,
				'ItemName'   => mb_substr( $item_name, 0, 100 ),
				'ItemCount'  => 1,
				'ItemWord'   => '式',
				'ItemPrice'  => $amount,
				'ItemTaxType'=> '1',
				'ItemAmount' => $amount,
			] ],
		];

		$result = Helper::post( '/B2CInvoice/Allowance', $data );
		if ( ! $result['ok'] ) {
			$order->add_order_note( sprintf(
				/* translators: %s: error message */
				__( '綠界折讓失敗：%s', 'mo-ectools' ),
				$result['message']
			) );
			return [ 'ok' => false, 'message' => $result['message'] ];
		}

		$resp = $result['data'] ?? [];
		$allowance_no = (string) ( $resp['IA_Allow_No'] ?? '' );
		$order->update_meta_data( Keys::ECPAY_INVOICE_ALLOWANCE_NO, $allowance_no );
		$order->update_meta_data( Keys::ECPAY_INVOICE_ALLOWANCE_AMT, $amount );
		$order->add_order_note( sprintf(
			/* translators: 1: allowance no, 2: amount */
			__( '綠界折讓單 %1$s 開立成功（金額 %2$d）', 'mo-ectools' ),
			$allowance_no,
			$amount
		) );
		$order->save();

		return [ 'ok' => true, 'message' => $result['message'], 'allowance_no' => $allowance_no ];
	}
}
