<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EzpayInvoice\Operations;

use Moksafowo\Modules\EzpayInvoice\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;


final class Allowance {

	public static function run( \WC_Order $order, int $amount ): array {
		$inv = (string) $order->get_meta( Keys::EZPAY_INVOICE_NUMBER );
		if ( '' === $inv || 'zero' === $inv || 'negative' === $inv ) {
			return [
				'ok'      => false,
				'message' => __( '此訂單沒有可折讓的 ezPay 發票。', 'moksa-for-woocommerce' ),
			];
		}
		if ( $amount <= 0 ) {
			return [
				'ok'      => false,
				'message' => __( '折讓金額需大於 0。', 'moksa-for-woocommerce' ),
			];
		}

		$item_name = '其他';
		foreach ( $order->get_items() as $i ) {
			$item_name = (string) $i->get_name();
			break;
		}

		$args = [
			'RespondType'     => 'JSON',
			'Version'         => '1.3',
			'TimeStamp'       => (string) time(),
			'InvoiceNo'       => $inv,
			'MerchantOrderNo' => Helper::generate_merchant_order_no( $order->get_id() ) . 'A',
			'ItemName'        => mb_substr( $item_name, 0, 30 ),
			'ItemCount'       => '1',
			'ItemUnit'        => __( '式', 'moksa-for-woocommerce' ),
			'ItemPrice'       => (string) $amount,
			'ItemAmt'         => (string) $amount,
			'ItemTaxAmt'      => '0',
			'TotalAmt'        => $amount,
			'BuyerEmail'      => (string) $order->get_billing_email(),
			'Status'          => '1',
		];

		Helper::log(
			'allowance request',
			[
				'order_id'   => $order->get_id(),
				'invoice_no' => $inv,
				'amount'     => $amount,
			]
		);

		$result = Helper::post( '/Api/allowance_issue', $args );
		if ( ! $result['ok'] ) {
			$order->add_order_note(
				sprintf(
				/* translators: %s: error */
					__( 'ezPay 折讓失敗：%s', 'moksa-for-woocommerce' ),
					$result['message'] ?? ''
				)
			);
			$order->save();
			return [
				'ok'      => false,
				'message' => $result['message'] ?? '',
			];
		}

		$data         = $result['data'] ?? [];
		$allowance_no = (string) ( $data['AllowanceNo'] ?? '' );

		$order->update_meta_data( Keys::EZPAY_INVOICE_ALLOWANCE_NO, $allowance_no );
		$order->update_meta_data( Keys::EZPAY_INVOICE_ALLOWANCE_AMT, (string) $amount );
		$order->add_order_note(
			sprintf(
			/* translators: 1: allowance no, 2: amount */
				__( 'ezPay 折讓單 %1$s 開立成功（金額 NT$%2$d）', 'moksa-for-woocommerce' ),
				$allowance_no,
				$amount
			)
		);
		$order->save();

		return [
			'ok'           => true,
			'message'      => 'OK',
			'allowance_no' => $allowance_no,
		];
	}
}
