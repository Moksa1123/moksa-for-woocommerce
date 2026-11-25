<?php
/**
 * 取貨／到貨才付款的訂單：發票等訂單完成才開。
 *
 * @package Moksafowo
 */

declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * 貨到付款與超商取貨付款的訂單一下單就是「處理中」，但錢要等客人取貨才收到。
 * 「付款完成就開」模式下，這類訂單改等訂單「完成」才開，客人沒取貨就不會有發票要作廢。
 */
final class PayOnDelivery {

	/**
	 * 取貨／到貨才付款的付款方式。
	 *
	 * @return string[]
	 */
	public static function methods(): array {
		$methods = array(
			'cod',
			'moksafowo_pchomepay_cvs711',
			'moksafowo_pchomepay_cvsfamily',
			'moksafowo_pchomepay_cvshilife',
		);
		/**
		 * 篩選「取貨／到貨才付款」的付款方式；這些訂單的發票等訂單完成才開。
		 *
		 * @param string[] $methods 付款方式 ID。
		 */
		$filtered = apply_filters( 'moksafowo_invoice_pay_on_delivery_methods', $methods );
		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $methods;
	}

	public static function is_pay_on_delivery( \WC_Order $order ): bool {
		$is = in_array( (string) $order->get_payment_method(), self::methods(), true )
			|| 'yes' === $order->get_meta( '_moksafowo_payuni_shipping_pay_on_delivery' );
		/**
		 * 篩選單張訂單是不是取貨／到貨才付款（例如在統一金流付款頁選了取貨付款，付款方式 ID 不是 cod）。
		 *
		 * @param bool      $is    目前判斷。
		 * @param \WC_Order $order 訂單。
		 */
		return (bool) apply_filters( 'moksafowo_invoice_order_is_pay_on_delivery', $is, $order );
	}

	/**
	 * 「付款完成就開」模式下，這張訂單現在要不要先等：取貨付款且還沒完成就等。
	 */
	public static function should_wait( \WC_Order $order, string $when ): bool {
		return 'paid' === $when && self::is_pay_on_delivery( $order ) && ! $order->has_status( 'completed' );
	}
}
