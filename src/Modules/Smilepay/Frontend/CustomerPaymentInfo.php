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
					'label' => __( 'Bank code', 'moksa-for-woocommerce' ),
					'value' => $bank,
				];
			}
			$rows[] = [
				'label' => __( 'Virtual account', 'moksa-for-woocommerce' ),
				'value' => $atm_acct,
			];
			return $rows;
		}

		if ( '' !== $ibon_no ) {
			return [
				[
					'label' => __( 'ibon payment code', 'moksa-for-woocommerce' ),
					'value' => $ibon_no,
				],
			];
		}

		if ( '' !== $fami_no ) {
			return [
				[
					'label' => __( 'FamiPort payment code', 'moksa-for-woocommerce' ),
					'value' => $fami_no,
				],
			];
		}

		if ( '' !== $barcode1 ) {
			$rows = [];
			foreach ( [ Keys::SMILEPAY_PAY_BARCODE_1, Keys::SMILEPAY_PAY_BARCODE_2, Keys::SMILEPAY_PAY_BARCODE_3 ] as $i => $key ) {
				$bc = (string) $order->get_meta( $key );
				if ( '' !== $bc ) {
					/* translators: %d: barcode segment index */
					$label  = sprintf( __( 'Barcode segment %d', 'moksa-for-woocommerce' ), $i + 1 );
					$rows[] = [
						'label' => $label,
						'value' => $bc,
					];
				}
			}
			return $rows;
		}

		return [];
	}
}
