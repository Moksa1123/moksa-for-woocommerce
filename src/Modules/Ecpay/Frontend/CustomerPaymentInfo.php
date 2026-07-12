<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Frontend;

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

		$atm_acct = (string) $order->get_meta( Keys::ECPAY_ATM_V_ACCOUNT );
		$cvs_no   = (string) $order->get_meta( Keys::ECPAY_CVS_PAYMENT_NO );
		$barcode1 = (string) $order->get_meta( Keys::ECPAY_BARCODE_1 );

		if ( '' !== $atm_acct ) {
			$rows = [];
			$bank = (string) $order->get_meta( Keys::ECPAY_ATM_BANK_CODE );
			if ( '' !== $bank ) {
				$rows[] = [
					'label' => __( '銀行代碼', 'mo-ectools' ),
					'value' => $bank,
				];
			}
			$rows[] = [
				'label' => __( '虛擬帳號', 'mo-ectools' ),
				'value' => $atm_acct,
			];
			$expire = (string) $order->get_meta( Keys::ECPAY_ATM_EXPIRE_DATE );
			if ( '' !== $expire ) {
				$rows[] = [
					'label' => __( '繳費期限', 'mo-ectools' ),
					'value' => $expire,
				];
			}
			return $rows;
		}

		if ( '' !== $cvs_no ) {
			$rows   = [
				[
					'label' => __( '繳費代碼', 'mo-ectools' ),
					'value' => $cvs_no,
				],
			];
			$expire = (string) $order->get_meta( Keys::ECPAY_CVS_EXPIRE_DATE );
			if ( '' !== $expire ) {
				$rows[] = [
					'label' => __( '繳費期限', 'mo-ectools' ),
					'value' => $expire,
				];
			}
			return $rows;
		}

		if ( '' !== $barcode1 ) {
			$rows = [];
			foreach ( [ Keys::ECPAY_BARCODE_1, Keys::ECPAY_BARCODE_2, Keys::ECPAY_BARCODE_3 ] as $i => $key ) {
				$bc = (string) $order->get_meta( $key );
				if ( '' !== $bc ) {
					$rows[] = [
						/* translators: %d: barcode segment index */
						'label' => sprintf( __( '條碼第 %d 段', 'mo-ectools' ), $i + 1 ),
						'value' => $bc,
					];
				}
			}
			$expire = (string) $order->get_meta( Keys::ECPAY_BARCODE_EXPIRE_DATE );
			if ( '' !== $expire ) {
				$rows[] = [
					'label' => __( '繳費期限', 'mo-ectools' ),
					'value' => $expire,
				];
			}
			return $rows;
		}

		return [];
	}
}
