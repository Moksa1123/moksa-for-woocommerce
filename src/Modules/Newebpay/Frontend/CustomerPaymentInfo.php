<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Frontend;

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

		$atm_acct = (string) $order->get_meta( Keys::NEWEBPAY_ATM_CODE_NO );
		$cvs_no   = (string) $order->get_meta( Keys::NEWEBPAY_CVS_CODE_NO );
		$barcode1 = (string) $order->get_meta( Keys::NEWEBPAY_BARCODE_1 );

		if ( '' !== $atm_acct ) {
			$rows = [];
			$bank = (string) $order->get_meta( Keys::NEWEBPAY_ATM_BANK_CODE );
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
			$expire = (string) $order->get_meta( Keys::NEWEBPAY_ATM_EXPIRE_DATE );
			if ( '' !== $expire ) {
				$rows[] = [
					'key'   => 'deadline',
					'label' => __( 'Pay before', 'moksa-for-woocommerce' ),
					'value' => $expire,
				];
			}
			return $rows;
		}

		if ( '' !== $cvs_no ) {
			$rows   = [
				[
					'key'   => 'cvs_code',
					'label' => __( 'Payment code', 'moksa-for-woocommerce' ),
					'value' => $cvs_no,
				],
			];
			$expire = (string) $order->get_meta( Keys::NEWEBPAY_CVS_EXPIRE_DATE );
			if ( '' !== $expire ) {
				$rows[] = [
					'key'   => 'deadline',
					'label' => __( 'Pay before', 'moksa-for-woocommerce' ),
					'value' => $expire,
				];
			}
			return $rows;
		}

		if ( '' !== $barcode1 ) {
			$rows = [];
			foreach ( [ Keys::NEWEBPAY_BARCODE_1, Keys::NEWEBPAY_BARCODE_2, Keys::NEWEBPAY_BARCODE_3 ] as $i => $key ) {
				$bc = (string) $order->get_meta( $key );
				if ( '' !== $bc ) {
					$rows[] = [
						'key'   => 'barcode_' . ( $i + 1 ),
						/* translators: %d: barcode segment index */
						'label' => sprintf( __( 'Barcode segment %d', 'moksa-for-woocommerce' ), $i + 1 ),
						'value' => $bc,
					];
				}
			}
			$expire = (string) $order->get_meta( Keys::NEWEBPAY_BARCODE_EXPIRE_DATE );
			if ( '' !== $expire ) {
				$rows[] = [
					'key'   => 'deadline',
					'label' => __( 'Pay before', 'moksa-for-woocommerce' ),
					'value' => $expire,
				];
			}
			return $rows;
		}

		return [];
	}
}
