<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Admin;

use Moksafowo\Modules\Newebpay\Api\PaymentRequest;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * AFTEE 先享後付請款（NPA-B62）。藍新後台設成手動請款時，要整筆請款才會撥款；
 * 交易成立後 89 天內可請，金額須等於訂單完成金額。
 */
final class BnplSettle {

	private const ACTION = 'moksafowo_newebpay_bnpl_settle';

	public static function init(): void {
		add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_action' ], 10, 2 );
		add_action( 'woocommerce_order_action_' . self::ACTION, [ __CLASS__, 'settle' ] );
	}

	public static function add_action( array $actions, $order = null ): array {
		if ( $order instanceof \WC_Order && self::can_settle( $order ) ) {
			$actions[ self::ACTION ] = __( 'Request AFTEE capture from NewebPay', 'moksa-for-woocommerce' );
		}
		return $actions;
	}

	private static function can_settle( \WC_Order $order ): bool {
		return str_starts_with( (string) $order->get_payment_method(), 'moksafowo_newebpay' )
			&& 'AFTEE' === (string) $order->get_meta( Keys::NEWEBPAY_PAYMENT_TYPE )
			&& $order->is_paid()
			&& '' === (string) $order->get_meta( Keys::NEWEBPAY_BNPL_SETTLED_AT );
	}

	public static function settle( \WC_Order $order ): void {
		if ( ! current_user_can( 'edit_shop_orders' ) || ! self::can_settle( $order ) ) {
			return;
		}
		$mtn    = (string) $order->get_meta( Keys::NEWEBPAY_MERCHANT_ORDER_NO );
		$amount = (int) round( (float) $order->get_total() - (float) $order->get_total_refunded() );
		$result = PaymentRequest::bnpl_settle(
			[
				'MerchantOrderNo' => $mtn,
				'Amt'             => $amount,
				'PaymentType'     => 'AFTEE',
			]
		);
		if ( empty( $result['ok'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'The AFTEE capture request failed: %s', 'moksa-for-woocommerce' ),
					(string) ( $result['message'] ?? '' )
				)
			);
			return;
		}
		$order->update_meta_data( Keys::NEWEBPAY_BNPL_SETTLED_AT, current_time( 'mysql' ) );
		$order->add_order_note(
			sprintf(
				/* translators: %s: amount */
				__( 'AFTEE capture of NT$%s was requested from NewebPay.', 'moksa-for-woocommerce' ),
				$amount
			)
		);
		$order->save();
	}
}
