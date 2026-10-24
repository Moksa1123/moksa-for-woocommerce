<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayInvoice\Operations;

use Moksafowo\Modules\EcpayInvoice\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 作廢折讓（AllowanceInvalid）。開過折讓的發票要先把折讓作廢，發票才作廢得了。
 */
final class AllowanceInvalid {

	/** 這張發票開過的折讓。舊版只存最後一張的編號，清單是空的時以它補上。 */
	public static function allowances( \WC_Order $order ): array {
		$list = $order->get_meta( Keys::ECPAY_INVOICE_ALLOWANCES );
		if ( is_array( $list ) && ! empty( $list ) ) {
			return array_values( $list );
		}
		$legacy = (string) $order->get_meta( Keys::ECPAY_INVOICE_ALLOWANCE_NO );
		if ( '' === $legacy ) {
			return [];
		}
		return [
			[
				'no'     => $legacy,
				'amount' => (int) $order->get_meta( Keys::ECPAY_INVOICE_ALLOWANCE_AMT ),
				'voided' => false,
			],
		];
	}

	public static function run( \WC_Order $order, string $allowance_no, string $reason ): array {
		$inv = (string) $order->get_meta( Keys::ECPAY_INVOICE_NUMBER );
		if ( '' === $inv || '' === $allowance_no ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no allowance to void.', 'moksa-for-woocommerce' ),
			];
		}

		$result = Helper::post(
			'/B2CInvoice/AllowanceInvalid',
			[
				'MerchantID'  => Helper::merchant_id(),
				'InvoiceNo'   => $inv,
				'AllowanceNo' => $allowance_no,
				'Reason'      => mb_substr( '' !== trim( $reason ) ? trim( $reason ) : 'void', 0, 20 ),
			]
		);
		if ( ! $result['ok'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: allowance number, 2: error message */
					__( 'ECPay allowance %1$s could not be voided: %2$s', 'moksa-for-woocommerce' ),
					$allowance_no,
					$result['message']
				)
			);
			$order->save();
			return [
				'ok'      => false,
				'message' => $result['message'],
			];
		}

		$list = self::allowances( $order );
		foreach ( $list as &$row ) {
			if ( (string) ( $row['no'] ?? '' ) === $allowance_no ) {
				$row['voided'] = true;
			}
		}
		unset( $row );
		$order->update_meta_data( Keys::ECPAY_INVOICE_ALLOWANCES, $list );
		$order->add_order_note(
			sprintf(
				/* translators: %s: allowance number */
				__( 'ECPay allowance %s was voided', 'moksa-for-woocommerce' ),
				$allowance_no
			)
		);
		$order->save();
		return [
			'ok'      => true,
			'message' => $result['message'],
		];
	}

	/** 作廢發票前先作廢還有效的折讓；任何一張失敗就回傳錯誤，不繼續作廢發票。 */
	public static function void_all( \WC_Order $order, string $reason ): array {
		foreach ( self::allowances( $order ) as $row ) {
			if ( ! empty( $row['voided'] ) ) {
				continue;
			}
			$res = self::run( $order, (string) ( $row['no'] ?? '' ), $reason );
			if ( ! $res['ok'] ) {
				return $res;
			}
		}
		return [
			'ok'      => true,
			'message' => 'OK',
		];
	}
}
