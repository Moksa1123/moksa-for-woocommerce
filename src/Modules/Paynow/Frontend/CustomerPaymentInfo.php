<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Paynow\Frontend;

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

		$atm_no  = (string) $order->get_meta( Keys::PAYNOW_ATM_NO );
		$bar1    = (string) $order->get_meta( Keys::PAYNOW_BARCODE_1 );
		$ibon    = (string) $order->get_meta( Keys::PAYNOW_IBON_NO );
		$fami    = (string) $order->get_meta( Keys::PAYNOW_FAMIPORT_NO );
		$icash   = (string) $order->get_meta( Keys::PAYNOW_ICASH_NO );

		if ( '' !== $atm_no ) {
			$rows = [];
			$bank = (string) $order->get_meta( Keys::PAYNOW_ATM_BANK_CODE );
			if ( '' !== $bank ) {
				$rows[] = [ 'label' => __( '銀行代碼', 'mo-ectools' ), 'value' => $bank ];
			}
			$rows[] = [ 'label' => __( '虛擬帳號', 'mo-ectools' ), 'value' => $atm_no ];
			return self::append( $rows, (string) $order->get_meta( Keys::PAYNOW_ATM_DUE_DATE ) );
		}

		if ( '' !== $bar1 ) {
			$rows = [];
			foreach ( [ Keys::PAYNOW_BARCODE_1, Keys::PAYNOW_BARCODE_2, Keys::PAYNOW_BARCODE_3 ] as $i => $key ) {
				$bc = (string) $order->get_meta( $key );
				if ( '' !== $bc ) {
					/* translators: %d: barcode segment index */
					$rows[] = [ 'label' => sprintf( __( '條碼第 %d 段', 'mo-ectools' ), $i + 1 ), 'value' => $bc ];
				}
			}
			return self::append( $rows, (string) $order->get_meta( Keys::PAYNOW_BARCODE_DUE_DATE ) );
		}

		$code = '' !== $ibon ? $ibon : ( '' !== $fami ? $fami : $icash );
		if ( '' !== $code ) {
			return self::append(
				[ [ 'label' => __( '超商繳費代碼', 'mo-ectools' ), 'value' => $code ] ],
				(string) $order->get_meta( Keys::PAYNOW_CODE_DUE_DATE )
			);
		}

		return [];
	}

	
	private static function append( array $rows, string $due ): array {
		if ( '' !== $due && ! empty( $rows ) ) {
			$rows[] = [ 'label' => __( '繳費期限', 'mo-ectools' ), 'value' => $due ];
		}
		return $rows;
	}
}
