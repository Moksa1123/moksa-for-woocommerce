<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Smilepay\Frontend;

use Moksafowo\Modules\Shared\Frontend\PaymentInfoBox;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;


final class CustomerPaymentInfo {

	public static function init(): void {
		PaymentInfoBox::register( [ __CLASS__, 'resolve' ] );
	}


	public static function resolve( \WC_Order $order ): array {
		if ( $order->is_paid() ) {
			return [];
		}

		$atm_acct = (string) $order->get_meta( Keys::SMILEPAY_PAY_ATM_NO );
		$ibon_no  = (string) $order->get_meta( Keys::SMILEPAY_PAY_IBON_NO );
		$fami_no  = (string) $order->get_meta( Keys::SMILEPAY_PAY_FAMI_NO );
		$barcode1 = (string) $order->get_meta( Keys::SMILEPAY_PAY_BARCODE_1 );

		if ( '' !== $atm_acct ) {
			$rows = [];
			$bank = (string) $order->get_meta( Keys::SMILEPAY_PAY_ATM_BANK_NO );
			if ( '' !== $bank ) {
				$rows[] = [
					'key'   => 'atm_bank',
					'label' => __( 'Bank code', 'moksa-for-woocommerce' ),
					'value' => $bank,
				];
			}
			$rows[] = [
				'key'   => 'atm_account',
				'label' => __( 'Virtual account', 'moksa-for-woocommerce' ),
				'value' => $atm_acct,
			];
			return self::with_deadline( $order, $rows );
		}

		if ( '' !== $ibon_no ) {
			return self::with_deadline(
				$order,
				[
					[
						'key'   => 'cvs_code',
						'label' => __( 'ibon payment code', 'moksa-for-woocommerce' ),
						'value' => $ibon_no,
					],
				]
			);
		}

		if ( '' !== $fami_no ) {
			return self::with_deadline(
				$order,
				[
					[
						'key'   => 'cvs_code',
						'label' => __( 'FamiPort payment code', 'moksa-for-woocommerce' ),
						'value' => $fami_no,
					],
				]
			);
		}

		if ( '' !== $barcode1 ) {
			$rows = [];
			foreach ( [ Keys::SMILEPAY_PAY_BARCODE_1, Keys::SMILEPAY_PAY_BARCODE_2, Keys::SMILEPAY_PAY_BARCODE_3 ] as $i => $key ) {
				$bc = (string) $order->get_meta( $key );
				if ( '' !== $bc ) {
					/* translators: %d: barcode segment index */
					$label  = sprintf( __( 'Barcode segment %d', 'moksa-for-woocommerce' ), $i + 1 );
					$rows[] = [
						'key'   => 'barcode_' . ( $i + 1 ),
						'label' => $label,
						'value' => $bc,
					];
				}
			}
			return self::with_deadline( $order, $rows );
		}

		return [];
	}

	/**
	 * 補上繳費期限。
	 *
	 * SmilePay 的 Pay_End_Date 一直有存進訂單，但這支 resolver 從來沒把它輸出，
	 * 顧客因此看不到期限 —— 其餘四家金流都有給。
	 *
	 * @param \WC_Order                                  $order 訂單。
	 * @param array<int,array<string,string>>            $rows  既有的列。
	 * @return array<int,array<string,string>>
	 */
	private static function with_deadline( \WC_Order $order, array $rows ): array {
		$end = (string) $order->get_meta( Keys::SMILEPAY_PAY_END_DATE );
		if ( '' !== $end ) {
			$rows[] = [
				'key'   => 'deadline',
				'label' => __( 'Pay before', 'moksa-for-woocommerce' ),
				'value' => $end,
			];
		}
		return $rows;
	}
}
