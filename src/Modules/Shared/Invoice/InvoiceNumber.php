<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Invoice;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 這張訂單實際開立的發票號碼，不管是哪一家開的。
 */
final class InvoiceNumber {

	/** 發票號碼 meta → 作廢時間 meta。 */
	private const SOURCES = [
		Keys::ECPAY_INVOICE_NUMBER    => Keys::ECPAY_INVOICE_INVALID_AT,
		Keys::EZPAY_INVOICE_NUMBER    => Keys::EZPAY_INVALID_AT,
		Keys::AMEGO_INVOICE_NUMBER    => Keys::AMEGO_INVOICE_INVALID_AT,
		Keys::PAYNOW_INVOICE_NUMBER   => Keys::PAYNOW_INVOICE_INVALID_AT,
		Keys::SMILEPAY_INVOICE_NUMBER => Keys::SMILEPAY_INVOICE_INVALID_AT,
	];

	/**
	 * 有效的優先；商家換過發票商時，作廢的那張只在找不到有效發票時才回傳。
	 *
	 * @return array{number: string, voided: bool}
	 */
	public static function of( \WC_Order $order ): array {
		$voided = '';
		foreach ( self::SOURCES as $number_key => $invalid_key ) {
			$number = (string) $order->get_meta( $number_key );
			if ( '' === $number ) {
				continue;
			}
			if ( '' === (string) $order->get_meta( $invalid_key ) ) {
				return [
					'number' => $number,
					'voided' => false,
				];
			}
			$voided = '' === $voided ? $number : $voided;
		}

		// PAYUNi 金流隨付款開立的發票：狀態 1 已開立、2 開立失敗、5 已作廢。
		$number = (string) $order->get_meta( Keys::PAYUNI_EINVOICE_NO );
		$status = (string) $order->get_meta( Keys::PAYUNI_EINVOICE_STATUS );
		if ( '' !== $number && '2' !== $status ) {
			if ( '5' !== $status ) {
				return [
					'number' => $number,
					'voided' => false,
				];
			}
			$voided = '' === $voided ? $number : $voided;
		}

		return [
			'number' => $voided,
			'voided' => '' !== $voided,
		];
	}
}
