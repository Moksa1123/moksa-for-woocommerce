<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\AmegoInvoice\Operations;

use Moksafowo\Modules\AmegoInvoice\Api\Helper;
use Moksafowo\Modules\AmegoInvoice\Api\Request;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;


final class Allowance {


	public static function run( \WC_Order $order, int $amount ): array {
		$inv = (string) $order->get_meta( Keys::AMEGO_INVOICE_NUMBER );
		if ( '' === $inv || 'zero' === $inv || 'negative' === $inv ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no Amego invoice to raise an allowance against.', 'moksa-for-woocommerce' ),
			];
		}
		if ( $amount <= 0 ) {
			return [
				'ok'      => false,
				'message' => __( 'The allowance amount must be greater than 0.', 'moksa-for-woocommerce' ),
			];
		}

		$item_name = '其他';
		foreach ( $order->get_items() as $i ) {
			$item_name = (string) $i->get_name();
			break;
		}

		// 原發票日期：開立時存的是 UTC 時間，換成台灣日期。
		$issued_at = (string) $order->get_meta( Keys::AMEGO_INVOICE_ISSUED_AT );
		if ( '' === $issued_at ) {
			return [
				'ok'      => false,
				'message' => __( 'The Amego invoice date for this order was not found, so an allowance cannot be raised here. Please raise it in the Amego dashboard.', 'moksa-for-woocommerce' ),
			];
		}
		$invoice_date = ( new \DateTimeImmutable( $issued_at, new \DateTimeZone( 'UTC' ) ) )
			->setTimezone( new \DateTimeZone( 'Asia/Taipei' ) )
			->format( 'Ymd' );

		// g0401 的單價、小計、合計都是未稅，稅額另列（官方範例：含稅 4578 → 4360 + 218）。
		$untaxed = (int) round( $amount / 1.05, 0 );
		$tax     = $amount - $untaxed;

		$allowance_number = 'A' . substr( (string) $order->get_id(), 0, 6 ) . substr( (string) time(), -6 );  // ≤16 chars
		$args             = [
			'AllowanceNumber'   => $allowance_number,
			// 2025/1/1 起 AllowanceType=1（買方申請）已禁用，必為 2（賣方開立折讓通知單）
			'AllowanceType'     => 2,
			'AllowanceDate'     => current_time( 'Ymd' ),  // Amego spec: YYYYMMDD（站台時區當日）
			'BuyerIdentifier'   => (string) ( $order->get_meta( Keys::INVOICE_BUYER_UBN ) ?: '0000000000' ),
			'BuyerName'         => trim( $order->get_billing_last_name() . $order->get_billing_first_name() ) ?: '客人',
			'BuyerEmailAddress' => (string) $order->get_billing_email(),
			'ProductItem'       => [
				[
					'OriginalInvoiceNumber' => $inv,
					'OriginalInvoiceDate'   => (int) $invoice_date,
					'OriginalDescription'   => mb_substr( $item_name, 0, 256 ),
					'Quantity'              => 1,
					'UnitPrice'             => $untaxed,
					'Amount'                => $untaxed,
					'Tax'                   => $tax,
					'TaxType'               => 1,
				],
			],
			'TaxAmount'         => $tax,
			'TotalAmount'       => $untaxed,
		];

		Helper::log(
			'allowance request',
			[
				'order_id'   => $order->get_id(),
				'invoice_no' => $inv,
				'amount'     => $amount,
			]
		);

		// g0401 spec：data 必為陣列（即使單張）— 同 f0501 模式
		$resp = Request::post( '/json/g0401', [ $args ] );
		if ( ! $resp['ok'] ) {
			$order->add_order_note(
				sprintf(
				/* translators: %s: error */
					__( 'The Amego allowance failed: %s', 'moksa-for-woocommerce' ),
					$resp['message']
				)
			);
			$order->save();
			return [
				'ok'      => false,
				'message' => $resp['message'],
			];
		}

		$data         = $resp['data'];
		$allowance_no = (string) ( $data['allowance_number'] ?? $args['AllowanceNumber'] );

		$order->update_meta_data( Keys::AMEGO_INVOICE_ALLOWANCE_NO, $allowance_no );
		$order->update_meta_data( Keys::AMEGO_INVOICE_ALLOWANCE_AMT, (string) $amount );
		$order->add_order_note(
			sprintf(
			/* translators: 1: allowance no, 2: amount */
				__( 'Amego allowance %1$s was issued for NT$%2$d', 'moksa-for-woocommerce' ),
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
