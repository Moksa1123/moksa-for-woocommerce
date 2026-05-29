<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Smilepay\Frontend;

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

		$atm_acct = (string) $order->get_meta( Keys::SMILEPAY_PAY_ATM_NO );
		$ibon_no  = (string) $order->get_meta( Keys::SMILEPAY_PAY_IBON_NO );
		$fami_no  = (string) $order->get_meta( Keys::SMILEPAY_PAY_FAMI_NO );
		$barcode1 = (string) $order->get_meta( Keys::SMILEPAY_PAY_BARCODE_1 );

		if ( '' !== $atm_acct ) {
			$rows = [];
			$bank = (string) $order->get_meta( Keys::SMILEPAY_PAY_ATM_BANK_NO );
			if ( '' !== $bank ) {
				$rows[] = [ 'label' => __( '銀行代碼', 'mo-ectools' ), 'value' => $bank ];
			}
			$rows[] = [ 'label' => __( '虛擬帳號', 'mo-ectools' ), 'value' => $atm_acct ];
			return $rows;
		}

		if ( '' !== $ibon_no ) {
			return [ [ 'label' => __( 'ibon 繳費代碼', 'mo-ectools' ), 'value' => $ibon_no ] ];
		}

		if ( '' !== $fami_no ) {
			return [ [ 'label' => __( 'FamiPort 繳費代碼', 'mo-ectools' ), 'value' => $fami_no ] ];
		}

		if ( '' !== $barcode1 ) {
			$rows = [];
			foreach ( [ Keys::SMILEPAY_PAY_BARCODE_1, Keys::SMILEPAY_PAY_BARCODE_2, Keys::SMILEPAY_PAY_BARCODE_3 ] as $i => $key ) {
				$bc = (string) $order->get_meta( $key );
				if ( '' !== $bc ) {
					/* translators: %d: barcode segment index */
					$rows[] = [ 'label' => sprintf( __( '條碼第 %d 段', 'mo-ectools' ), $i + 1 ), 'value' => $bc ];
				}
			}
			return $rows;
		}

		return [];
	}
}
