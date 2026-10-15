<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Payuni\Frontend;

use Moksafowo\Modules\Payuni\PayuniPayment;
use Moksafowo\Modules\Payuni\Utils\BankType;
use Moksafowo\Modules\Payuni\Utils\OrderMeta;
use Moksafowo\Modules\Shared\Frontend\PaymentInfoBox;

defined( 'ABSPATH' ) || exit;

/**
 * PAYUNi 的 ATM／超商代碼繳費資訊，接上共用的繳費資訊區塊與繳費通知信（同其他五家）。
 *
 * 依訂單存的付款類型判斷，不看 gateway：單一入口（Unified）訂單、或商家之後改了顯示模式，
 * 舊訂單都照樣顯示。
 */
final class CustomerPaymentInfo {

	public static function init(): void {
		PaymentInfoBox::register( [ __CLASS__, 'resolve' ] );
	}

	public static function resolve( \WC_Order $order ): array {
		if ( $order->is_paid() || ! str_starts_with( (string) $order->get_payment_method(), 'moksafowo_payuni' ) ) {
			return [];
		}
		$meta     = static fn( string $key ): string => (string) $order->get_meta( PayuniPayment::get_order_meta_key( $order, $key ) );
		$pay_type = $meta( OrderMeta::PAY_TYPE );

		if ( '2' === $pay_type && '' !== $meta( OrderMeta::AMT_PAY_NO ) ) {
			$rows = [];
			$bank = $meta( OrderMeta::AMT_BANK_TYPE );
			if ( '' !== $bank ) {
				$name   = (string) BankType::get_name( $bank );
				$rows[] = [
					'key'   => 'atm_bank',
					'label' => __( 'Bank code', 'moksa-for-woocommerce' ),
					'value' => '' !== $name && $name !== $bank ? $bank . ' ' . $name : $bank,
				];
			}
			$rows[] = [
				'key'   => 'atm_account',
				'label' => __( 'Virtual account', 'moksa-for-woocommerce' ),
				'value' => $meta( OrderMeta::AMT_PAY_NO ),
			];
			return self::with_deadline( $rows, $meta( OrderMeta::AMT_EXPIRE_DATE ) );
		}

		if ( '3' === $pay_type && '' !== $meta( OrderMeta::CVS_PAY_NO ) ) {
			$rows = [
				[
					'key'   => 'cvs_code',
					'label' => __( 'Payment code', 'moksa-for-woocommerce' ),
					'value' => $meta( OrderMeta::CVS_PAY_NO ),
				],
			];
			return self::with_deadline( $rows, $meta( OrderMeta::CVS_EXPIRE_DATE ) );
		}

		return [];
	}

	private static function with_deadline( array $rows, string $expire ): array {
		if ( '' !== $expire ) {
			$rows[] = [
				'key'   => 'deadline',
				'label' => __( 'Pay before', 'moksa-for-woocommerce' ),
				'value' => $expire,
			];
		}
		return $rows;
	}
}
