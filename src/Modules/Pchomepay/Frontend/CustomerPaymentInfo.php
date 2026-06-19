<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Pchomepay\Frontend;

use MoksaWeb\Mowc\Modules\Shared\Frontend\PaymentInfoBox;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;


final class CustomerPaymentInfo {

	public static function init(): void {
		PaymentInfoBox::register( [ __CLASS__, 'resolve' ] );
	}


	public static function resolve( \WC_Order $order ): array {
		if ( $order->is_paid() ) {
			return [];
		}

		$virtual_account = (string) $order->get_meta( Keys::PCHOMEPAY_VIRTUAL_ACCOUNT );
		$pincode         = (string) $order->get_meta( Keys::PCHOMEPAY_PINCODE );
		$barcode1        = (string) $order->get_meta( Keys::PCHOMEPAY_BARCODE_1 );

		if ( '' !== $virtual_account ) {
			$rows = [];
			$bank = (string) $order->get_meta( Keys::PCHOMEPAY_BANK_CODE );
			if ( '' !== $bank ) {
				$rows[] = [
					'label' => __( '銀行代碼', 'mo-ectools' ),
					'value' => $bank,
				];
			}
			$rows[] = [
				'label' => __( '虛擬帳號', 'mo-ectools' ),
				'value' => $virtual_account,
			];
			return self::append_expire( $order, $rows );
		}

		if ( '' !== $pincode ) {
			return self::append_expire(
				$order,
				[
					[
						'label' => __( '超商繳費代碼', 'mo-ectools' ),
						'value' => $pincode,
					],
				]
			);
		}

		if ( '' !== $barcode1 ) {
			$rows = [];
			foreach ( [ Keys::PCHOMEPAY_BARCODE_1, Keys::PCHOMEPAY_BARCODE_2, Keys::PCHOMEPAY_BARCODE_3 ] as $i => $key ) {
				$bc = (string) $order->get_meta( $key );
				if ( '' !== $bc ) {
					$rows[] = [
						/* translators: %d: barcode segment index */
						'label' => sprintf( __( '條碼第 %d 段', 'mo-ectools' ), $i + 1 ),
						'value' => $bc,
					];
				}
			}
			return self::append_expire( $order, $rows );
		}

		return [];
	}


	private static function append_expire( \WC_Order $order, array $rows ): array {
		$exp = (string) $order->get_meta( Keys::PCHOMEPAY_EXPIRE_DATE );
		if ( '' === $exp || empty( $rows ) ) {
			return $rows;
		}
		// 14 碼 YYYYMMDDHHMMSS → 友善格式
		if ( 1 === preg_match( '/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $exp, $m ) ) {
			$exp = "{$m[1]}/{$m[2]}/{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
		}
		$rows[] = [
			'label' => __( '繳費期限', 'mo-ectools' ),
			'value' => $exp,
		];
		return $rows;
	}
}
