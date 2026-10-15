<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow\Frontend;

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

		$atm_no = (string) $order->get_meta( Keys::PAYNOW_ATM_NO );
		$bar1   = (string) $order->get_meta( Keys::PAYNOW_BARCODE_1 );
		$ibon   = (string) $order->get_meta( Keys::PAYNOW_IBON_NO );
		$fami   = (string) $order->get_meta( Keys::PAYNOW_FAMIPORT_NO );
		$icash  = (string) $order->get_meta( Keys::PAYNOW_ICASH_NO );

		if ( '' !== $atm_no ) {
			$rows = [];
			$bank = (string) $order->get_meta( Keys::PAYNOW_ATM_BANK_CODE );
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
				'value' => $atm_no,
			];
			return self::append( $rows, (string) $order->get_meta( Keys::PAYNOW_ATM_DUE_DATE ) );
		}

		if ( '' !== $bar1 ) {
			$rows = [];
			foreach ( [ Keys::PAYNOW_BARCODE_1, Keys::PAYNOW_BARCODE_2, Keys::PAYNOW_BARCODE_3 ] as $i => $key ) {
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
			return self::append( $rows, (string) $order->get_meta( Keys::PAYNOW_BARCODE_DUE_DATE ) );
		}

		// 依哪個代碼有值決定標籤，顧客才知道要去 7-11 還是全家；不單看 CodeType，通知可能沒帶。
		if ( '' !== $ibon || '' !== $fami || '' !== $icash ) {
			if ( '' !== $ibon ) {
				$label = __( 'ibon payment code', 'moksa-for-woocommerce' );
				$code  = $ibon;
			} elseif ( '' !== $fami ) {
				$label = __( 'FamiPort payment code', 'moksa-for-woocommerce' );
				$code  = $fami;
			} else {
				$label = __( 'iCash payment code', 'moksa-for-woocommerce' );
				$code  = $icash;
			}
			$rows = [
				[
					'key'   => 'cvs_code',
					'label' => $label,
					'value' => $code,
				],
			];
			$url  = (string) $order->get_meta( Keys::PAYNOW_ICASH_PAY_URL );
			if ( '' === $ibon && '' === $fami && str_starts_with( $url, 'https://' ) ) {
				$rows[] = [
					'key'   => 'pay_url',
					'label' => __( 'iCash payment link', 'moksa-for-woocommerce' ),
					'value' => $url,
					'url'   => $url,
				];
			}
			return self::append( $rows, (string) $order->get_meta( Keys::PAYNOW_CODE_DUE_DATE ) );
		}

		return [];
	}


	private static function append( array $rows, string $due ): array {
		if ( '' !== $due && ! empty( $rows ) ) {
			$rows[] = [
				'key'   => 'deadline',
				'label' => __( 'Pay before', 'moksa-for-woocommerce' ),
				'value' => $due,
			];
		}
		return $rows;
	}
}
