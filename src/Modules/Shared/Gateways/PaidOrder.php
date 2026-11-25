<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Gateways;

use Moksafowo\Logging\Logger;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 金流回報付款成功時一律走這裡，不直接呼叫 payment_complete()。
 *
 * WC 預設允許已取消的訂單被標成已付款（接著扣庫存、開發票、建物流單）。
 * 已取消的訂單收到付款通知時不直接入帳；但逾時被自動取消後顧客才去繳費也是真的收到錢，
 * 所以不丟掉：改成保留並寫備註，由商家確認後決定出貨或退款。
 *
 * 保留的訂單 WC 也允許標成已付款，所以要記下標記：同一張單之後再來的通知（包括同一則通知重送）都不動。
 */
final class PaidOrder {

	/**
	 * 測試模式（$sandbox）收到的付款通知一律不入帳：測試環境沒有實際收款，而且綠界、TapPay
	 * 沒填測試金鑰時用的是公開的測試金鑰，簽章擋不住。所以一律轉保留並寫備註，由商家自己判斷。
	 *
	 * @param \WC_Order $order          訂單。
	 * @param string    $transaction_id 金流交易編號。
	 * @param bool      $sandbox        這則通知是在測試模式下收到的。
	 * @return bool 訂單真的被標成已付款才回 true；呼叫端據此決定要不要寫「付款完成」備註。
	 */
	public static function complete( \WC_Order $order, string $transaction_id = '', bool $sandbox = false ): bool {
		if ( $sandbox ) {
			self::hold_sandbox( $order, $transaction_id );
			return false;
		}
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

	private static function hold_sandbox( \WC_Order $order, string $transaction_id ): void {
		$context = [
			'order_id'       => $order->get_id(),
			'payment_method' => $order->get_payment_method(),
			'transaction_id' => $transaction_id,
		];
		// 同一張單重送的通知只記 log，不再重複寫備註。
		if ( 'yes' === $order->get_meta( Keys::SANDBOX_PAYMENT_HELD ) ) {
			Logger::warning( 'payment-callback', 'test-mode payment reported again — ignored', $context );
			return;
		}
		$order->update_meta_data( Keys::SANDBOX_PAYMENT_HELD, 'yes' );
		$note = sprintf(
			/* translators: %s: payment method title */
			__( '%s reported a successful payment while in test mode. Test payments are not real, so the order was not marked as paid. If this is a real order, turn off test mode in the payment settings and check the payment before fulfilling it.', 'moksa-for-woocommerce' ),
			$order->get_payment_method_title() ?: $order->get_payment_method()
		);
		// 已經是保留中（例如 ATM 取號後）時 WC 沒有狀態轉換，update_status 不會寫備註。
		if ( $order->has_status( 'on-hold' ) ) {
			$order->add_order_note( $note );
			$order->save();
		} else {
			$order->update_status( 'on-hold', $note );
		}
		Logger::warning( 'payment-callback', 'test-mode payment reported — put on hold', $context );
	}

	private function __construct() {}
}
