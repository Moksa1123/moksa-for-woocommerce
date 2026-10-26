<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Api;

use Moksafowo\Compatibility\WpSubscription;
use Moksafowo\Modules\Newebpay\Gateways\CreditPeriod;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 定期定額接 WPSubscription：通知解密、去重與重複委託的處理都在 PeriodHandler，這裡只負責
 * 把結果寫到 WPSubscription 的訂閱與續訂單，以及訂閱狀態變更時改委託。
 *
 * 委託資料存在訂閱（subscrpt_order 文章）的 post meta，key 與 WooCommerce Subscriptions 相同。
 *
 * 扣款日由藍新決定。WPSubscription 的下次扣款日設在藍新扣款日當天 23:00：通知白天先到，
 * 訂閱就不會先被轉成「已過期」、顧客也不會收到過期通知；通知真的沒來，WPSubscription 才在當晚
 * 建待付款的續訂單，之後通知補到時再入帳到那張。
 */
final class WpsPeriod {

	/**
	 * 由這裡改訂閱狀態時，別再回頭呼叫藍新改委託。
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	public static function boot(): void {
		if ( ! WpSubscription::active() ) {
			return;
		}
		add_filter( 'subscrpt_subscription_next_date', [ self::class, 'next_date' ], 20, 2 );
		add_action( 'moksafowo_wps_scheduled_payment_' . CreditPeriod::GATEWAY_ID, [ self::class, 'renewal_created' ], 10, 2 );
		add_action( 'subscrpt_subscription_cancelled', [ self::class, 'terminate_on_cancel' ] );
		add_action( 'subscrpt_subscription_pending_cancellation', [ self::class, 'terminate' ] );
		add_action( 'subscrpt_subscription_on_hold', [ self::class, 'suspend' ] );
		add_action( 'subscrpt_subscription_activated', [ self::class, 'activated' ] );
	}

	public static function owns( \WC_Order $order ): bool {
		return [] !== WpSubscription::subscription_ids( $order->get_id() );
	}

	private static function is_ours( int $subscription_id ): bool {
		return CreditPeriod::GATEWAY_ID === WpSubscription::gateway_of( $subscription_id )
			&& '' !== (string) get_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_NO, true );
	}

	/** 委託建立完成、首期入帳之前：記下委託與藍新的下一期扣款日。 */
	public static function on_creation( \WC_Order $order, string $period_no, string $mer_order_no, string $date_array ): void {
		$dates = array_values( array_filter( array_map( 'trim', explode( ',', $date_array ) ) ) );
		foreach ( WpSubscription::subscription_ids( $order->get_id() ) as $subscription_id ) {
			update_post_meta( $subscription_id, Keys::WPS_GATEWAY, CreditPeriod::GATEWAY_ID );
			update_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_NO, $period_no );
			update_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_ORDER_NO, $mer_order_no );
			delete_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_ENDED );
			delete_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_SUSPENDED );
			if ( isset( $dates[1] ) ) {
				self::remember_next( $subscription_id, $dates[1] );
			}
		}
	}

	/**
	 * 第 2 期起的授權結果（NPA-N050）。
	 *
	 * @param \WC_Order                                                      $order   建立委託的訂單。
	 * @param array{Status:string,Message:string,Result:array<string,mixed>} $payload 解密後的通知。
	 */
	public static function apply_period( \WC_Order $order, array $payload ): void {
		$result          = $payload['Result'];
		$times           = (int) ( $result['AlreadyTimes'] ?? 0 );
		$total           = (int) ( $result['TotalTimes'] ?? 0 );
		$order_no        = (string) ( $result['OrderNo'] ?? '' );
		$trade_no        = (string) ( $result['TradeNo'] ?? '' );
		$success         = 'SUCCESS' === $payload['Status'] && '00' === (string) ( $result['RespondCode'] ?? '' );
		$ids             = WpSubscription::subscription_ids( $order->get_id() );
		$subscription_id = $ids[0] ?? 0;
		if ( ! $subscription_id ) {
			return;
		}

		$seen = get_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_TRADE_NOS, true );
		$seen = is_array( $seen ) ? $seen : [];
		$ref  = '' !== $order_no ? $order_no : $trade_no;
		if ( '' === $ref || in_array( $ref, $seen, true ) ) {
			return;
		}
		if ( $success && ! self::confirmed_by_query( $subscription_id, $times ) ) {
			WpSubscription::note(
				$subscription_id,
				/* translators: %s: NewebPay order number */
				sprintf( __( 'A NewebPay renewal notice (%s) did not match the mandate status at NewebPay, so no renewal order was created. Check the mandate in the NewebPay dashboard.', 'moksa-for-woocommerce' ), $ref )
			);
			return;
		}
		$seen[] = $ref;
		update_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_TRADE_NOS, array_slice( $seen, -60 ) );
		if ( ! empty( $result['NextAuthDate'] ) ) {
			self::remember_next( $subscription_id, (string) $result['NextAuthDate'] );
		}

		$ended         = in_array( WpSubscription::status( $subscription_id ), [ 'cancelled', 'pe_cancelled', 'completed' ], true );
		self::$syncing = true;
		$renewal       = WpSubscription::current_unpaid_renewal( $subscription_id ) ?? WpSubscription::create_renewal( $subscription_id );
		self::$syncing = false;
		if ( null === $renewal ) {
			WpSubscription::note(
				$subscription_id,
				/* translators: 1: payment number, 2: NewebPay trade number */
				sprintf( __( 'NewebPay charged payment %1$d (trade number %2$s), but no renewal order could be created because the subscription has no paid order to copy from. Record this payment manually.', 'moksa-for-woocommerce' ), $times, '' !== $trade_no ? $trade_no : '-' )
			);
			if ( $success ) {
				PeriodHandler::notify_admin( $order );
			}
			return;
		}

		$renewal->set_payment_method( CreditPeriod::GATEWAY_ID );
		if ( '' !== $order_no ) {
			Helper::remember_mtn( $renewal, $order_no );
		}
		$renewal->update_meta_data( Keys::NEWEBPAY_TRADE_NO, $trade_no );
		$renewal->update_meta_data( Keys::NEWEBPAY_PAYMENT_TYPE, 'CREDIT' );
		$renewal->update_meta_data( Keys::NEWEBPAY_PAID_AMT, (string) (int) ( $result['AuthAmt'] ?? 0 ) );
		$renewal->update_meta_data( Keys::NEWEBPAY_PAY_TIME, (string) ( $result['AuthDate'] ?? '' ) );
		$renewal->save();

		if ( ! $success ) {
			// 授權失敗後藍新仍會照排程扣下一期，所以不終止委託。標「保留」而不是「失敗」：
			// WPSubscription 會把失敗的續訂單連帶取消訂閱；保留的這張等藍新下一期扣款成功時入帳。
			$renewal->update_status(
				'on-hold',
				/* translators: 1: payment number, 2: NewebPay message */
				sprintf( __( 'NewebPay recurring payment %1$d failed: %2$s. NewebPay will still try the next scheduled payment.', 'moksa-for-woocommerce' ), $times, $payload['Message'] )
			);
			return;
		}

		if ( $ended ) {
			$renewal->set_transaction_id( $trade_no );
			$renewal->update_status(
				'on-hold',
				/* translators: 1: payment number, 2: NewebPay trade number */
				sprintf( __( 'NewebPay charged payment %1$d (trade number %2$s) although this subscription was already cancelled. Refund this order and make sure the mandate is terminated in the NewebPay dashboard.', 'moksa-for-woocommerce' ), $times, $trade_no )
			);
			PeriodHandler::notify_admin( $renewal );
			self::alter( $subscription_id, 'terminate', true );
			return;
		}

		$paid = (int) ( $result['AuthAmt'] ?? 0 );
		if ( $paid !== (int) ceil( (float) $renewal->get_total() ) ) {
			$renewal->set_transaction_id( $trade_no );
			$renewal->update_status(
				'on-hold',
				/* translators: 1: paid amount, 2: order total */
				sprintf( __( 'NewebPay reported a payment of %1$s, which does not match the order total of %2$s. Please check the payment before fulfilling the order.', 'moksa-for-woocommerce' ), $paid, $renewal->get_total() )
			);
			return;
		}

		self::$syncing = true;
		$renewal->payment_complete( $trade_no );
		self::$syncing = false;
		$renewal->add_order_note(
			/* translators: 1: payment number, 2: total payments, 3: NewebPay trade number */
			sprintf( __( 'NewebPay recurring payment %1$d of %2$d completed — trade number %3$s', 'moksa-for-woocommerce' ), $times, $total, $trade_no )
		);

		// 通知比 WPSubscription 到期早到時訂閱還在啟用中，狀態沒變就不會重算下次扣款日，這裡直接改成藍新的下一期。
		$next = (int) get_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_NEXT_TS, true );
		if ( 'active' === WpSubscription::status( $subscription_id ) && $next > time() ) {
			update_post_meta( $subscription_id, '_subscrpt_next_date', $next );
			update_post_meta( $subscription_id, '_subscrpt_next_date_set_by_order', $renewal->get_id() );
		}

		if ( $total > 0 && $times >= $total ) {
			update_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_ENDED, current_time( 'mysql' ) );
			if ( WpSubscription::max_payments( $subscription_id ) <= 0 ) {
				WpSubscription::note(
					$subscription_id,
					/* translators: %d: number of payments */
					sprintf( __( 'The NewebPay mandate has finished all %d payments, so nothing more will be charged. Ask the customer to subscribe again to continue.', 'moksa-for-woocommerce' ), $total )
				);
			}
		}
	}

	/** 卡片異動（4.3.4）：非 ACTIVE 時藍新已停扣、委託終止，訂閱在這一期結束後取消。 */
	public static function apply_card_update( \WC_Order $order, string $card_status ): void {
		foreach ( WpSubscription::subscription_ids( $order->get_id() ) as $subscription_id ) {
			if ( 'ACTIVE' === $card_status ) {
				WpSubscription::note( $subscription_id, __( 'NewebPay updated the card expiry date for this subscription. Payments continue as scheduled.', 'moksa-for-woocommerce' ) );
				continue;
			}
			update_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_ENDED, current_time( 'mysql' ) );
			WpSubscription::note(
				$subscription_id,
				/* translators: %s: NewebPay card status */
				sprintf( __( 'NewebPay stopped this recurring payment because the card can no longer be charged (%s). The subscription ends after the current period; ask the customer to subscribe again with another card.', 'moksa-for-woocommerce' ), $card_status )
			);
			if ( 'active' === WpSubscription::status( $subscription_id ) ) {
				self::$syncing = true;
				WpSubscription::set_status( $subscription_id, 'pe_cancelled' );
				self::$syncing = false;
			}
		}
	}

	/** WPSubscription 到期時建的續訂單：款項由藍新照委託扣，這裡只留說明。 */
	public static function renewal_created( $renewal, $subscription_id ): void {
		$subscription_id = (int) $subscription_id;
		// 收到藍新通知時自己建的續訂單接著就會入帳，不用說明。
		if ( self::$syncing || ! $renewal instanceof \WC_Order || ! self::is_ours( $subscription_id ) ) {
			return;
		}
		$renewal->add_order_note(
			'' !== (string) get_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_ENDED, true )
				? __( 'The NewebPay mandate for this subscription has ended, so NewebPay will not charge this order. Ask the customer to pay it or to subscribe again.', 'moksa-for-woocommerce' )
				: __( 'NewebPay charges this renewal on its own schedule. The order is updated when NewebPay sends the payment notice.', 'moksa-for-woocommerce' )
		);
	}

	/** WPSubscription 重算下次扣款日時，藍新付款的訂閱改用藍新的排程。 */
	public static function next_date( $next, $subscription_id ) {
		$subscription_id = (int) $subscription_id;
		if ( CreditPeriod::GATEWAY_ID !== WpSubscription::gateway_of( $subscription_id ) ) {
			return $next;
		}
		$ts = (int) get_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_NEXT_TS, true );
		return $ts > time() ? $ts : $next;
	}

	private static function remember_next( int $subscription_id, string $date ): void {
		$local = date_create_immutable( trim( $date ) . ' 23:00:00', wp_timezone() );
		if ( $local ) {
			update_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_NEXT_TS, $local->getTimestamp() );
		}
	}

	/** 每期通知只能靠解密確認來源；再用委託單查詢核對已授權期數，查不到時照收。 */
	private static function confirmed_by_query( int $subscription_id, int $times ): bool {
		$query = Period::query( (string) get_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_ORDER_NO, true ), (string) get_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_NO, true ) );
		if ( ! $query['ok'] ) {
			return true;
		}
		return (int) ( $query['data']['AlreadyTimes'] ?? 0 ) >= $times;
	}

	public static function terminate( $subscription_id ): void {
		self::alter( (int) $subscription_id, 'terminate' );
	}

	/** 已取消：扣款失敗連帶取消的不終止（商家手動把續訂單標失敗時才會發生，藍新下一期仍會扣）。 */
	public static function terminate_on_cancel( $subscription_id ): void {
		if ( ! WpSubscription::cancelled_by_failed_payment( (int) $subscription_id ) ) {
			self::alter( (int) $subscription_id, 'terminate' );
		}
	}

	public static function suspend( $subscription_id ): void {
		$subscription_id = (int) $subscription_id;
		if ( self::$syncing || ! self::is_ours( $subscription_id ) ) {
			return;
		}
		if ( 'yes' !== get_option( 'moksafowo_newebpay_mandate_changes', 'no' ) ) {
			WpSubscription::note( $subscription_id, __( 'This subscription is on hold, but NewebPay keeps charging the card because mandate status changes are not enabled. Cancel the subscription instead if the customer should not be charged.', 'moksa-for-woocommerce' ) );
			return;
		}
		if ( self::alter( $subscription_id, 'suspend' ) ) {
			update_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_SUSPENDED, current_time( 'mysql' ) );
		}
	}

	/** 重新啟用：委託已終止就不能維持啟用；本外掛暫停過的委託要啟用回來。 */
	public static function activated( $subscription_id ): void {
		$subscription_id = (int) $subscription_id;
		if ( self::$syncing || ! self::is_ours( $subscription_id ) ) {
			return;
		}
		if ( '' !== (string) get_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_ENDED, true ) ) {
			self::$syncing = true;
			WpSubscription::set_status( $subscription_id, 'pe_cancelled' );
			self::$syncing = false;
			WpSubscription::note( $subscription_id, __( 'This subscription cannot be reactivated because its NewebPay mandate has ended, so it was set back to pending cancellation. Ask the customer to subscribe again.', 'moksa-for-woocommerce' ) );
			return;
		}
		if ( '' === (string) get_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_SUSPENDED, true ) ) {
			return;
		}
		if ( self::alter( $subscription_id, 'restart' ) ) {
			delete_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_SUSPENDED );
			return;
		}
		self::$syncing = true;
		WpSubscription::set_status( $subscription_id, 'on-hold' );
		self::$syncing = false;
		WpSubscription::note( $subscription_id, __( 'The NewebPay mandate could not be restarted, so the subscription was put back on hold. Restart it in the NewebPay dashboard first.', 'moksa-for-woocommerce' ) );
	}

	/** @return bool 藍新是否接受這次修改。 */
	private static function alter( int $subscription_id, string $type, bool $force = false ): bool {
		if ( ( self::$syncing && ! $force ) || ! self::is_ours( $subscription_id ) ) {
			return false;
		}
		$order_no = (string) get_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_ORDER_NO, true );
		if ( '' === $order_no ) {
			return false;
		}
		// 已終止的委託不再重送（待取消 → 已取消會連續觸發兩次）。
		if ( 'terminate' === $type && '' !== (string) get_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_ENDED, true ) ) {
			return true;
		}
		$resp = Period::alter_status( $order_no, (string) get_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_NO, true ), $type );
		if ( $resp['ok'] && 'terminate' === $type ) {
			update_post_meta( $subscription_id, Keys::NEWEBPAY_PERIOD_ENDED, current_time( 'mysql' ) );
		}
		WpSubscription::note(
			$subscription_id,
			$resp['ok']
				/* translators: 1: action, 2: NewebPay message */
				? sprintf( __( 'NewebPay mandate updated (%1$s): %2$s', 'moksa-for-woocommerce' ), $type, $resp['message'] )
				/* translators: 1: action, 2: NewebPay message */
				: sprintf( __( 'The NewebPay mandate could not be updated (%1$s): %2$s. Update it in the NewebPay dashboard so the customer is charged correctly.', 'moksa-for-woocommerce' ), $type, $resp['message'] )
		);
		if ( ! $resp['ok'] && 'terminate' === $type ) {
			$parent = WpSubscription::parent_order( $subscription_id );
			if ( $parent ) {
				PeriodHandler::notify_admin( $parent );
			}
		}
		return $resp['ok'];
	}
}
