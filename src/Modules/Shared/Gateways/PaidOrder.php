<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Gateways;

use Moksafowo\Logging\Logger;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 金流回報付款成功時一律走這裡，不直接呼叫 payment_complete()。
 *
 * WC 預設允許已取消的訂單被標成已付款，重送的舊通知就能把取消的單改回「處理中」，
 * 接著扣庫存、開發票、建物流單。但逾時被自動取消後顧客才去繳費也是真的收到錢，
 * 所以不丟掉：改成保留並寫備註，由商家確認後決定出貨或退款。
 *
 * 保留的訂單 WC 也允許標成已付款，所以要記下標記：同一張單之後再來的通知都不動，
 * 否則同一則通知送兩次就繞過了。
 */
final class PaidOrder {

	/**
	 * @return bool 訂單真的被標成已付款才回 true；呼叫端據此決定要不要寫「付款完成」備註。
	 */
	public static function complete( \WC_Order $order, string $transaction_id = '' ): bool {
		$held = 'yes' === $order->get_meta( Keys::PAID_AFTER_CANCEL ) && $order->has_status( 'on-hold' );
		if ( ! $held && ! $order->has_status( 'cancelled' ) ) {
			$order->payment_complete( $transaction_id );
			return true;
		}

		$context = [
			'order_id'       => $order->get_id(),
			'payment_method' => $order->get_payment_method(),
			'transaction_id' => $transaction_id,
		];
		if ( $held ) {
			Logger::warning( 'payment-callback', 'payment reported again for an order held after cancellation — ignored', $context );
			return false;
		}

		if ( '' !== $transaction_id ) {
			$order->set_transaction_id( $transaction_id );
		}
		$order->update_meta_data( Keys::PAID_AFTER_CANCEL, 'yes' );
		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: %s: payment method title */
				__( '%s reported a payment for this order after it was cancelled. The order was not marked as paid. Check the payment, then fulfil the order or refund the customer.', 'moksa-for-woocommerce' ),
				$order->get_payment_method_title() ?: $order->get_payment_method()
			)
		);
		Logger::warning( 'payment-callback', 'payment reported for a cancelled order — put on hold', $context );
		return false;
	}

	private function __construct() {}
}
