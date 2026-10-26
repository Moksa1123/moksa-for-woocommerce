<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Api;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Modules\Newebpay\Gateways\CreditPeriod;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 定期定額的通知與同步（NDNP-1.0.8）。
 *
 * - return：首期授權後以 Form Post 導回（ReturnURL），內容是「建立完成」（4.3.2）。
 * - notify：幕後通知（NotifyURL）。建立完成與每期授權完成（NPA-N050，4.3.3）都可能送來，
 *   CAU 卡片異動通知（4.3.4）只會送到商店申請 CAU 時登記的固定網址，若登記成這裡也一併處理。
 * - 訂閱狀態變更時呼叫修改委託狀態（NPA-B051）。
 *
 * 藍新不重送每期通知；收到第 n 期才建第 n 張續訂單，以「商店訂單編號_期數」去重。
 *
 * 扣款的是藍新，不是 WCS：只要委託還在，藍新就會照扣。所以這裡的原則是
 * 「錢已經扣了就一定要留下訂單紀錄，但不讓已取消的訂閱因此復活」，並且主動終止不該存在的委託。
 */
final class PeriodHandler {

	/**
	 * 由這裡改訂閱狀態時，別再回頭呼叫藍新改委託。
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	public static function boot(): void {
		add_action( 'woocommerce_api_moksafowo_newebpay_period_return', [ self::class, 'handle_return' ] );
		add_action( 'woocommerce_api_moksafowo_newebpay_period_notify', [ self::class, 'handle_notify' ] );
		WpsPeriod::boot();
		if ( ! Subscriptions::active() ) {
			return;
		}
		$id = CreditPeriod::GATEWAY_ID;
		add_action( 'woocommerce_subscription_cancelled_' . $id, [ self::class, 'terminate' ] );
		add_action( 'woocommerce_subscription_pending-cancel_' . $id, [ self::class, 'terminate' ] );
		add_action( 'woocommerce_subscription_expired_' . $id, [ self::class, 'terminate_if_running' ] );
		add_action( 'woocommerce_subscription_on-hold_' . $id, [ self::class, 'suspend' ] );
		add_action( 'woocommerce_subscription_activated_' . $id, [ self::class, 'restart' ] );
	}

	public static function handle_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- 藍新 Form Post 導回，無法帶 nonce；Period 只有持有金鑰的雙方解得開，另比對訂單金鑰。
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$hex      = isset( $_POST['Period'] ) ? sanitize_text_field( wp_unslash( $_POST['Period'] ) ) : '';
		// phpcs:enable

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$payload = '' !== $hex ? Period::decode( $hex ) : self::posted_failure( $order );
		if ( null !== $payload ) {
			self::route( $payload );
			$order = wc_get_order( $order_id );
		}

		// 幕後通知可能正拿著鎖處理同一筆；藍新回報成功就先帶到訂單完成頁。
		if ( $order->is_paid() || ( null !== $payload && 'SUCCESS' === $payload['Status'] ) ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
		wc_add_notice(
			null !== $payload && '' !== $payload['Message']
				/* translators: %s: NewebPay message */
				? sprintf( __( 'The NewebPay recurring payment was not set up: %s', 'moksa-for-woocommerce' ), $payload['Message'] )
				: __( 'The card payment did not go through. Please check the card and try again.', 'moksa-for-woocommerce' ),
			'error'
		);
		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	public static function handle_notify(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 藍新幕後通知，無 nonce；Period 為 AES 密文，解不開即拒絕。手冊註明可能以 multipart/form-data 送，$_POST 兩種都能讀。
		$hex     = isset( $_POST['Period'] ) ? sanitize_text_field( wp_unslash( $_POST['Period'] ) ) : '';
		$payload = '' !== $hex ? Period::decode( $hex ) : null;
		if ( null === $payload ) {
			Helper::log( 'period notify rejected: cannot decrypt' );
			status_header( 400 );
			exit;
		}
		self::route( $payload );
		status_header( 200 );
		echo 'OK';
		exit;
	}

	/**
	 * 委託建立失敗時（例：PER10031 商店未開定期定額）藍新導回的不是 Period，而是明文 Status／Message
	 * 加上加密的 Result。明文欄位可被偽造，所以只在 Result 解得開且屬於這張訂單時採用，且一律當失敗處理。
	 *
	 * @return array{Status:string,Message:string,Result:array<string,mixed>}|null
	 */
	private static function posted_failure( \WC_Order $order ): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 同 handle_return：藍新 Form Post 導回；Result 為 AES 密文。
		$status = isset( $_POST['Status'] ) ? sanitize_text_field( wp_unslash( $_POST['Status'] ) ) : '';
		$hex    = isset( $_POST['Result'] ) ? sanitize_text_field( wp_unslash( $_POST['Result'] ) ) : '';
		$msg    = isset( $_POST['Message'] ) ? sanitize_text_field( wp_unslash( $_POST['Message'] ) ) : '';
		// phpcs:enable
		if ( '' === $status || 'SUCCESS' === $status || '' === $hex || ! ctype_xdigit( $hex ) ) {
			return null;
		}
		$result = Helper::decrypt_trade_info( $hex );
		if ( ! is_array( $result ) ) {
			return null;
		}
		$result = map_deep( $result, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
		$mtn    = (string) ( $result['MerchantOrderNo'] ?? '' );
		if ( '' === $mtn || Helper::resolve_order_id( $mtn ) !== $order->get_id() ) {
			return null;
		}
		unset( $result['PeriodNo'], $result['TradeNo'], $result['AlreadyTimes'], $result['cardStatus'] );
		return [
			'Status'  => $status,
			'Message' => $msg,
			'Result'  => $result,
		];
	}

	/** @param array{Status:string,Message:string,Result:array<string,mixed>} $payload */
	private static function route( array $payload ): void {
		$result = $payload['Result'];
		if ( isset( $result['MerchantID'] ) && (string) $result['MerchantID'] !== Helper::merchant_id() ) {
			Helper::log( 'period payload for another merchant ignored' );
			return;
		}
		$mtn   = (string) ( $result['MerchantOrderNo'] ?? $result['MerOrderNo'] ?? '' );
		$id    = Helper::resolve_order_id( $mtn );
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			Helper::log( 'period notify: order not found', [ 'mtn' => $mtn ] );
			return;
		}

		// 導回與幕後通知可能同時到；同一張訂單一次只處理一筆。
		$lock = 'newebpay-period-' . $order->get_id();
		if ( ! Subscriptions::lock( $lock ) ) {
			return;
		}
		try {
			$order = wc_get_order( $order->get_id() );
			if ( isset( $result['cardStatus'] ) ) {
				self::apply_card_update( $order, $payload );
			} elseif ( isset( $result['AlreadyTimes'] ) ) {
				self::apply_period( $order, $payload );
			} else {
				self::apply_creation( $order, $payload );
			}
		} finally {
			Subscriptions::unlock( $lock );
		}
	}

	/** 建立完成（4.3.2）：PeriodStartType=2 時這筆授權就是首期款。 */
	private static function apply_creation( \WC_Order $order, array $payload ): void {
		$result    = $payload['Result'];
		$period_no = (string) ( $result['PeriodNo'] ?? '' );
		$trade_no  = (string) ( $result['TradeNo'] ?? '' );
		$code      = (string) ( $result['RespondCode'] ?? '00' );
		if ( 'SUCCESS' !== $payload['Status'] || '' === $period_no || '' === $trade_no || '00' !== $code ) {
			if ( ! $order->is_paid() ) {
				$order->update_status(
					'failed',
					sprintf(
						/* translators: 1: NewebPay status, 2: message */
						__( 'The NewebPay recurring payment was not set up (%1$s): %2$s', 'moksa-for-woocommerce' ),
						$payload['Status'],
						$payload['Message']
					)
				);
			}
			return;
		}

		$mer_order_no = (string) ( $result['MerchantOrderNo'] ?? $order->get_meta( Keys::NEWEBPAY_PERIOD_ORDER_NO ) );
		$stored       = self::stored_period_no( $order );

		if ( '' !== $stored && $stored !== $period_no ) {
			// 同一張訂單又建了一張委託（例如開兩個分頁各送一次）：多的那張立刻終止，首期款要人工退。
			// 不論第一張是否已入帳（可能因金額不符停在保留）都不能讓第二張覆蓋委託單號。
			self::terminate_foreign( $order, $mer_order_no, $period_no, $trade_no );
			return;
		}
		if ( $order->is_paid() ) {
			return;
		}

		// 通知只能靠解密確認來源；再用委託單查詢（NPA-B053）核對委託確實在扣款中且首期已授權。
		// 查詢 API 失敗（尚未開通等）時照收，不擋住正常訂單。
		$query = Period::query( $mer_order_no, $period_no );
		// 剛建立時狀態可能還是 0（驗證未完成），只擋明確不成立的 2（驗證失敗）、3（終止）。
		if ( $query['ok'] && in_array( (int) ( $query['data']['Status'] ?? -1 ), [ 2, 3 ], true ) ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: %s: NewebPay mandate number */
					__( 'A NewebPay recurring payment notice for mandate %s did not match the mandate status at NewebPay. Check it in the NewebPay dashboard before fulfilling the order.', 'moksa-for-woocommerce' ),
					$period_no
				)
			);
			return;
		}

		$card_no = (string) ( $result['CardNo'] ?? '' );
		$order->update_meta_data( Keys::NEWEBPAY_TRADE_NO, $trade_no );
		$order->update_meta_data( Keys::NEWEBPAY_PAYMENT_TYPE, 'CREDIT' );
		$order->update_meta_data( Keys::NEWEBPAY_PAID_AMT, (string) (int) ( $result['PeriodAmt'] ?? 0 ) );
		$order->update_meta_data( Keys::NEWEBPAY_PAY_TIME, (string) ( $result['AuthTime'] ?? '' ) );
		if ( '' !== $card_no ) {
			$order->update_meta_data( Keys::NEWEBPAY_CARD_LAST4, substr( $card_no, -4 ) );
		}
		$order->save();

		Subscriptions::store_payment_meta(
			$order,
			[
				Keys::NEWEBPAY_PERIOD_NO       => $period_no,
				Keys::NEWEBPAY_PERIOD_ORDER_NO => $mer_order_no,
				Keys::NEWEBPAY_PERIOD_ENDED    => '',
			]
		);
		WpsPeriod::on_creation( $order, $period_no, $mer_order_no, (string) ( $result['DateArray'] ?? '' ) );
		// 委託已在藍新扣款中，訂閱必須是自動續訂，取消時才會觸發終止委託。
		foreach ( Subscriptions::subscriptions_for( $order ) as $subscription ) {
			if ( CreditPeriod::GATEWAY_ID !== $subscription->get_payment_method() ) {
				$subscription->set_payment_method( CreditPeriod::GATEWAY_ID );
				$subscription->save();
			}
		}

		$paid  = (int) ( $result['PeriodAmt'] ?? 0 );
		$total = (int) ceil( (float) $order->get_total() );
		if ( $paid !== $total ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: paid amount, 2: order total */
					__( 'NewebPay reported a payment of %1$s, which does not match the order total of %2$s. Please check the payment before fulfilling the order.', 'moksa-for-woocommerce' ),
					$paid,
					$total
				)
			);
			return;
		}

		self::$syncing = true;
		$order->payment_complete( $trade_no );
		self::$syncing = false;
		$order->add_order_note(
			sprintf(
				/* translators: 1: NewebPay mandate number, 2: authorized periods */
				__( 'NewebPay recurring payment set up — mandate %1$s, %2$d payments scheduled. This order is the first payment.', 'moksa-for-woocommerce' ),
				$period_no,
				(int) ( $result['AuthTimes'] ?? 0 )
			)
		);

		$dates = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $result['DateArray'] ?? '' ) ) ) ) );
		if ( isset( $dates[1] ) ) {
			foreach ( Subscriptions::subscriptions_for( $order ) as $subscription ) {
				self::set_next_payment( $subscription, $dates[1] );
			}
		}
	}

	/** 每期授權完成（NPA-N050）。第 1 期是首期款（訂單本身），第 2 期起建續訂單。 */
	private static function apply_period( \WC_Order $order, array $payload ): void {
		$result    = $payload['Result'];
		$times     = (int) ( $result['AlreadyTimes'] ?? 0 );
		$total     = (int) ( $result['TotalTimes'] ?? 0 );
		$order_no  = (string) ( $result['OrderNo'] ?? '' );
		$trade_no  = (string) ( $result['TradeNo'] ?? '' );
		$period_no = (string) ( $result['PeriodNo'] ?? '' );
		$success   = 'SUCCESS' === $payload['Status'] && '00' === (string) ( $result['RespondCode'] ?? '' );

		// 不是這張訂單登記的委託（重複建立的那張）：不建續訂單，直接終止它。
		$stored = self::stored_period_no( $order );
		if ( '' !== $period_no && '' !== $stored && $period_no !== $stored ) {
			self::terminate_foreign( $order, (string) ( $result['MerchantOrderNo'] ?? '' ), $period_no, $success ? $trade_no : '' );
			return;
		}

		if ( $times <= 1 ) {
			if ( '' !== $order_no ) {
				// 每期交易在藍新的訂單編號是「委託單號_期數」，退款、查詢都要用它。
				Helper::remember_mtn( $order, $order_no );
				$order->save();
			}
			if ( $success && ! $order->is_paid() ) {
				self::$syncing = true;
				$order->payment_complete( $trade_no );
				self::$syncing = false;
			}
			return;
		}

		if ( WpsPeriod::owns( $order ) ) {
			WpsPeriod::apply_period( $order, $payload );
			return;
		}

		$subscriptions = Subscriptions::subscriptions_for( $order );
		$subscription  = $subscriptions[0] ?? null;
		if ( ! $subscription instanceof \WC_Subscription ) {
			Helper::log( 'period notify: subscription not found', [ 'order_id' => $order->get_id() ] );
			return;
		}

		$seen = $subscription->get_meta( Keys::NEWEBPAY_PERIOD_TRADE_NOS );
		$seen = is_array( $seen ) ? $seen : [];
		$ref  = '' !== $order_no ? $order_no : $trade_no;
		if ( '' === $ref || in_array( $ref, $seen, true ) ) {
			return;
		}

		if ( $success && ! self::confirmed_by_query( $subscription, $times ) ) {
			$subscription->add_order_note(
				sprintf(
					/* translators: %s: NewebPay order number */
					__( 'A NewebPay renewal notice (%s) did not match the mandate status at NewebPay, so no renewal order was created. Check the mandate in the NewebPay dashboard.', 'moksa-for-woocommerce' ),
					$ref
				)
			);
			return;
		}
		$seen[] = $ref;
		$subscription->update_meta_data( Keys::NEWEBPAY_PERIOD_TRADE_NOS, array_slice( $seen, -60 ) );
		$subscription->save();

		// 訂閱已取消或結束，藍新卻還在扣（修改委託沒開通、或終止失敗）：再試一次終止，
		// 這筆錢建成「保留」的續訂單請商家退款，不讓訂閱因為這筆付款復活。
		$ended = $subscription->has_status( [ 'cancelled', 'pending-cancel', 'expired' ] );

		self::$syncing = true;
		// 照 WCS 對金流商排程（PayPal）的做法：先轉保留、建續訂單、付款完成後 WCS 會重新啟用並排下一期。
		// 沒開放暫停（未勾選修改委託）時訂閱不能轉保留，WCS 會丟例外；直接建續訂單也一樣會更新扣款日。
		if ( $success && ! $ended && $subscription->has_status( 'active' ) && $subscription->can_be_updated_to( 'on-hold' ) ) {
			$subscription->update_status( 'on-hold' );
		}
		$renewal = wcs_create_renewal_order( $subscription );
		if ( is_wp_error( $renewal ) ) {
			self::$syncing = false;
			Helper::log( 'period notify: renewal order not created', [ 'error' => $renewal->get_error_message() ] );
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

		if ( $success && $ended ) {
			$renewal->set_transaction_id( $trade_no );
			$renewal->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: payment number, 2: NewebPay trade number */
					__( 'NewebPay charged payment %1$d (trade number %2$s) although this subscription was already cancelled. Refund this order and make sure the mandate is terminated in the NewebPay dashboard.', 'moksa-for-woocommerce' ),
					$times,
					$trade_no
				)
			);
			self::$syncing = false;
			self::notify_admin( $renewal );
			self::alter( $subscription, 'terminate', true );
			return;
		}

		$paid = (int) ( $result['AuthAmt'] ?? 0 );
		if ( $success && $paid !== (int) ceil( (float) $renewal->get_total() ) ) {
			// 續訂金額被改過（例如商家調價）但委託金額是建立時固定的：不自動入帳，請商家核對。
			$renewal->set_transaction_id( $trade_no );
			$renewal->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: paid amount, 2: order total */
					__( 'NewebPay reported a payment of %1$s, which does not match the order total of %2$s. Please check the payment before fulfilling the order.', 'moksa-for-woocommerce' ),
					$paid,
					$renewal->get_total()
				)
			);
		} elseif ( $success ) {
			$renewal->payment_complete( $trade_no );
			$renewal->add_order_note(
				sprintf(
					/* translators: 1: payment number, 2: total payments, 3: NewebPay trade number */
					__( 'NewebPay recurring payment %1$d of %2$d completed — trade number %3$s', 'moksa-for-woocommerce' ),
					$times,
					$total,
					$trade_no
				)
			);
		} else {
			// 授權失敗後藍新仍會照排程扣下一期（常見問題 A1），所以不終止委託。
			$renewal->update_status(
				'failed',
				sprintf(
					/* translators: 1: payment number, 2: NewebPay message */
					__( 'NewebPay recurring payment %1$d failed: %2$s. NewebPay will still try the next scheduled payment.', 'moksa-for-woocommerce' ),
					$times,
					$payload['Message']
				)
			);
		}
		self::$syncing = false;

		$subscription = wcs_get_subscription( $subscription->get_id() );
		if ( $total > 0 && $times >= $total ) {
			self::mandate_finished( $subscription, $total );
		} elseif ( ! empty( $result['NextAuthDate'] ) ) {
			self::set_next_payment( $subscription, (string) $result['NextAuthDate'] );
		}
	}

	/**
	 * 委託的期數扣完了。訂閱有結束日的話 WCS 會自己到期；沒有結束日（以 99 期建立，或藍新依卡片效期截短）
	 * 就再也不會有人扣款，訂閱轉保留並請顧客重新訂閱，不能讓它維持啟用卻不收錢。
	 */
	private static function mandate_finished( $subscription, int $total ): void {
		if ( ! $subscription instanceof \WC_Subscription ) {
			return;
		}
		$subscription->update_meta_data( Keys::NEWEBPAY_PERIOD_ENDED, current_time( 'mysql' ) );
		$subscription->save();
		if ( 0 !== (int) $subscription->get_time( 'end' ) || ! $subscription->has_status( 'active' ) ) {
			return;
		}
		self::stop(
			$subscription,
			sprintf(
			/* translators: %d: number of payments */
				__( 'The NewebPay mandate has finished all %d payments, so nothing more will be charged. Ask the customer to subscribe again to continue.', 'moksa-for-woocommerce' ),
				$total
			)
		);
	}

	/** CAU 卡片異動（4.3.4）：非 ACTIVE 時藍新已停扣、委託終止，訂閱要請顧客重新訂閱。 */
	private static function apply_card_update( \WC_Order $order, array $payload ): void {
		$result = $payload['Result'];
		$status = (string) ( $result['cardStatus'] ?? '' );
		WpsPeriod::apply_card_update( $order, $status );
		foreach ( Subscriptions::subscriptions_for( $order ) as $subscription ) {
			if ( 'ACTIVE' === $status ) {
				$subscription->add_order_note( __( 'NewebPay updated the card expiry date for this subscription. Payments continue as scheduled.', 'moksa-for-woocommerce' ) );
				continue;
			}
			// 委託已被藍新終止：記下來，之後重新啟用訂閱時不能假裝還會扣款。
			$subscription->update_meta_data( Keys::NEWEBPAY_PERIOD_ENDED, current_time( 'mysql' ) );
			$subscription->save();
			self::stop(
				$subscription,
				sprintf(
				/* translators: %s: NewebPay card status */
					__( 'NewebPay stopped this recurring payment because the card can no longer be charged (%s). Ask the customer to subscribe again with another card.', 'moksa-for-woocommerce' ),
					$status
				)
			);
		}
	}

	/** 每期通知只能靠解密確認來源；再用委託單查詢（NPA-B053）核對已授權期數，查不到時照收。 */
	private static function confirmed_by_query( \WC_Subscription $subscription, int $times ): bool {
		$query = Period::query( (string) $subscription->get_meta( Keys::NEWEBPAY_PERIOD_ORDER_NO ), (string) $subscription->get_meta( Keys::NEWEBPAY_PERIOD_NO ) );
		if ( ! $query['ok'] ) {
			return true;
		}
		return (int) ( $query['data']['AlreadyTimes'] ?? 0 ) >= $times;
	}

	/** 訂單（或它的訂閱）登記的委託單號。 */
	private static function stored_period_no( \WC_Order $order ): string {
		$stored = (string) $order->get_meta( Keys::NEWEBPAY_PERIOD_NO );
		if ( '' !== $stored ) {
			return $stored;
		}
		foreach ( Subscriptions::subscriptions_for( $order ) as $subscription ) {
			$stored = (string) $subscription->get_meta( Keys::NEWEBPAY_PERIOD_NO );
			if ( '' !== $stored ) {
				return $stored;
			}
		}
		return '';
	}

	/** 終止一張不屬於這個訂閱的委託（重複建立），並請商家退掉它已扣的款。 */
	private static function terminate_foreign( \WC_Order $order, string $mer_order_no, string $period_no, string $trade_no ): void {
		// 同一張委託的建立結果會由瀏覽器導回與幕後通知各送一次，終止與通知只做一次。
		$handled = $order->get_meta( Keys::NEWEBPAY_PERIOD_FOREIGN );
		$handled = is_array( $handled ) ? $handled : [];
		if ( in_array( $period_no, $handled, true ) ) {
			return;
		}
		$handled[] = $period_no;
		$order->update_meta_data( Keys::NEWEBPAY_PERIOD_FOREIGN, array_slice( $handled, -20 ) );
		$order->save();
		$resp = '' !== $mer_order_no ? Period::alter_status( $mer_order_no, $period_no, 'terminate' ) : [
			'ok'      => false,
			'message' => '',
		];
		$order->add_order_note(
			sprintf(
				/* translators: 1: NewebPay mandate number, 2: result, 3: NewebPay trade number */
				__( 'NewebPay created a second mandate (%1$s) for this order. Termination: %2$s. If it charged the card (trade number %3$s), refund that payment in the NewebPay dashboard.', 'moksa-for-woocommerce' ),
				$period_no,
				$resp['ok'] ? __( 'done', 'moksa-for-woocommerce' ) : ( '' !== $resp['message'] ? $resp['message'] : __( 'failed', 'moksa-for-woocommerce' ) ),
				'' !== $trade_no ? $trade_no : '-'
			)
		);
		self::notify_admin( $order );
	}

	/** 需要商家立刻處理（多扣款、取消後仍扣款）時寄信給網站管理員，免得只埋在備註裡。 */
	public static function notify_admin( \WC_Order $order ): void {
		wp_mail(
			(string) get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: order number */
				__( '[NewebPay] Order #%s needs a refund check', 'moksa-for-woocommerce' ),
				$order->get_order_number()
			),
			sprintf(
				/* translators: %s: order edit URL */
				__( 'NewebPay charged a recurring payment that should not have been charged. Open the order, read the latest note and refund the payment in the NewebPay dashboard if needed: %s', 'moksa-for-woocommerce' ),
				$order->get_edit_order_url()
			)
		);
	}

	private static function set_next_payment( $subscription, string $ymd ): void {
		if ( ! $subscription instanceof \WC_Subscription ) {
			return;
		}
		$local = date_create_immutable( $ymd . ' 10:00:00', wp_timezone() );
		if ( ! $local ) {
			return;
		}
		try {
			$subscription->update_dates( [ 'next_payment' => gmdate( 'Y-m-d H:i:s', $local->getTimestamp() ) ] );
		} catch ( \Exception $e ) {
			Helper::log( 'period next payment date not updated: ' . $e->getMessage() );
		}
	}

	public static function terminate( $subscription ): void {
		self::alter( $subscription, 'terminate' );
	}

	/** 訂閱到期：委託若還在扣款中（委託期數比訂閱長），一併終止。 */
	public static function terminate_if_running( $subscription ): void {
		if ( ! $subscription instanceof \WC_Subscription || '' !== (string) $subscription->get_meta( Keys::NEWEBPAY_PERIOD_ENDED ) ) {
			return;
		}
		$query = Period::query( (string) $subscription->get_meta( Keys::NEWEBPAY_PERIOD_ORDER_NO ), (string) $subscription->get_meta( Keys::NEWEBPAY_PERIOD_NO ) );
		// 委託單狀態：1 扣款中、5 暫停（4.6.2）。查不到時照樣終止，寧可多送一次。
		if ( ! $query['ok'] || in_array( (int) ( $query['data']['Status'] ?? 0 ), [ 1, 5 ], true ) ) {
			self::alter( $subscription, 'terminate' );
		}
	}

	public static function suspend( $subscription ): void {
		if ( self::alter( $subscription, 'suspend' ) && $subscription instanceof \WC_Subscription ) {
			$subscription->update_meta_data( Keys::NEWEBPAY_PERIOD_SUSPENDED, current_time( 'mysql' ) );
			$subscription->save();
		}
	}

	/** 重新啟用：委託已終止或啟用失敗時訂閱不能維持啟用（不會再扣款），轉回保留。 */
	public static function restart( $subscription ): void {
		if ( self::$syncing || ! $subscription instanceof \WC_Subscription ) {
			return;
		}
		if ( '' !== (string) $subscription->get_meta( Keys::NEWEBPAY_PERIOD_ENDED ) ) {
			self::hold_back( $subscription, __( 'This subscription cannot be reactivated because its NewebPay mandate has ended. Ask the customer to subscribe again.', 'moksa-for-woocommerce' ) );
			return;
		}
		// 只有我們曾把委託暫停過才需要啟用。訂閱從待付款、扣款失敗的保留轉回啟用時，委託本來就在扣款，
		// 送 restart 會被拒（PER10063），不能因此把正常的訂閱停掉。
		if ( '' === (string) $subscription->get_meta( Keys::NEWEBPAY_PERIOD_SUSPENDED ) ) {
			return;
		}
		if ( self::alter( $subscription, 'restart' ) ) {
			$subscription->update_meta_data( Keys::NEWEBPAY_PERIOD_SUSPENDED, '' );
			$subscription->save();
			return;
		}
		self::hold_back( $subscription, __( 'The NewebPay mandate could not be restarted, so the subscription was put back on hold. Restart it in the NewebPay dashboard first.', 'moksa-for-woocommerce' ) );
	}

	private static function hold_back( \WC_Subscription $subscription, string $note ): void {
		self::stop( $subscription, $note );
	}

	/**
	 * 委託已經不會再扣款：訂閱不能維持啟用。能轉保留就轉保留（顧客或商家之後可處理），
	 * 不支援暫停時（未勾選修改委託）WCS 不允許轉保留，改為取消，避免訂閱啟用卻永遠不收款。
	 */
	private static function stop( \WC_Subscription $subscription, string $note ): void {
		self::$syncing = true;
		try {
			if ( $subscription->can_be_updated_to( 'on-hold' ) ) {
				$subscription->update_status( 'on-hold', $note );
			} elseif ( $subscription->can_be_updated_to( 'cancelled' ) ) {
				$subscription->update_status( 'cancelled', $note );
			} else {
				$subscription->add_order_note( $note );
			}
		} catch ( \Exception $e ) {
			$subscription->add_order_note( $note );
			Helper::log( 'period subscription status not changed: ' . $e->getMessage() );
		} finally {
			self::$syncing = false;
		}
	}

	/** @return bool 藍新是否接受這次修改。 */
	private static function alter( $subscription, string $type, bool $force = false ): bool {
		if ( ( self::$syncing && ! $force ) || ! $subscription instanceof \WC_Subscription ) {
			return false;
		}
		$period_no = (string) $subscription->get_meta( Keys::NEWEBPAY_PERIOD_NO );
		$order_no  = (string) $subscription->get_meta( Keys::NEWEBPAY_PERIOD_ORDER_NO );
		if ( '' === $period_no || '' === $order_no ) {
			return false;
		}
		// 已終止的委託不再重送（pending-cancel → cancelled 會連續觸發兩次）。
		if ( '' !== (string) $subscription->get_meta( Keys::NEWEBPAY_PERIOD_ENDED ) && 'terminate' === $type ) {
			return true;
		}
		$resp = Period::alter_status( $order_no, $period_no, $type );
		if ( $resp['ok'] && 'terminate' === $type ) {
			$subscription->update_meta_data( Keys::NEWEBPAY_PERIOD_ENDED, current_time( 'mysql' ) );
			$subscription->save();
		}
		$subscription->add_order_note(
			$resp['ok']
				/* translators: 1: action, 2: NewebPay message */
				? sprintf( __( 'NewebPay mandate updated (%1$s): %2$s', 'moksa-for-woocommerce' ), $type, $resp['message'] )
				/* translators: 1: action, 2: NewebPay message */
				: sprintf( __( 'The NewebPay mandate could not be updated (%1$s): %2$s. Update it in the NewebPay dashboard so the customer is charged correctly.', 'moksa-for-woocommerce' ), $type, $resp['message'] )
		);
		if ( ! $resp['ok'] && 'terminate' === $type ) {
			self::notify_admin( $subscription );
		}
		return $resp['ok'];
	}
}
