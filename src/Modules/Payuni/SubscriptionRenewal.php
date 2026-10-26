<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Payuni;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Compatibility\WpSubscription;
use Moksafowo\Modules\Payuni\Api\PaymentRequest;
use Moksafowo\Modules\Payuni\Api\PaymentResponse;
use Moksafowo\Modules\Payuni\Gateways\Credit;
use Moksafowo\Modules\Payuni\Utils\OrderMeta;
use Moksafowo\Modules\Payuni\Utils\TradeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions 續扣：WCS 排程到期時，用首期約定的 CreditHash 走 PAYUNi 信用卡幕後 Token 交易。
 */
final class SubscriptionRenewal {

	private const CARD_META = [ OrderMeta::CREDIT_TOKEN_ID, OrderMeta::CREDIT_TOKEN_LIFE, OrderMeta::CREDIT_CARD_4NO ];

	public const CHECK_HOOK = 'moksafowo_payuni_renewal_check';

	public static function boot(): void {
		if ( Credit::wps_enabled() ) {
			add_filter( 'moksafowo_subscription_copied_meta_keys', [ self::class, 'copied_meta_keys' ] );
			add_action( 'moksafowo_wps_scheduled_payment_' . Credit::GATEWAY_ID, [ self::class, 'charge_wps' ] );
			add_action( self::CHECK_HOOK, [ self::class, 'check' ], 10, 2 );
			add_action( 'subscrpt_subscription_cancelled', [ self::class, 'revoke_on_wps_end' ] );
			add_action( 'moksafowo_wps_credentials_replaced', [ self::class, 'revoke_wps_replaced' ], 10, 2 );
		}
		if ( ! Subscriptions::active() ) {
			return;
		}
		add_action( 'woocommerce_scheduled_subscription_payment_' . Credit::GATEWAY_ID, [ self::class, 'charge' ], 10, 2 );
		if ( ! Credit::wps_enabled() ) {
			add_filter( 'moksafowo_subscription_copied_meta_keys', [ self::class, 'copied_meta_keys' ] );
			add_action( self::CHECK_HOOK, [ self::class, 'check' ], 10, 2 );
		}
		add_filter( 'woocommerce_subscription_payment_meta', [ self::class, 'payment_meta' ], 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta_' . Credit::GATEWAY_ID, [ self::class, 'validate_payment_meta' ], 10, 2 );
		add_action( 'woocommerce_subscription_failing_payment_method_updated_' . Credit::GATEWAY_ID, [ self::class, 'update_failing_payment_method' ], 10, 2 );
		add_action( 'woocommerce_subscription_status_cancelled', [ self::class, 'revoke_on_end' ] );
		add_action( 'woocommerce_subscription_status_expired', [ self::class, 'revoke_on_end' ] );
		add_action( 'moksafowo_subscription_credentials_replaced', [ self::class, 'revoke_replaced' ], 10, 2 );
	}

	public static function copied_meta_keys( $keys ): array {
		return array_merge( (array) $keys, self::CARD_META );
	}

	/** WPSubscription 到期建的續訂單：同樣用約定的卡幕後扣款。 */
	public static function charge_wps( $renewal ): void {
		if ( $renewal instanceof \WC_Order ) {
			self::charge( $renewal->get_total(), $renewal );
		}
	}

	public static function charge( $amount, $renewal ): void {
		if ( ! $renewal instanceof \WC_Order || ! $renewal->needs_payment() ) {
			return;
		}

		$credit_hash = (string) $renewal->get_meta( OrderMeta::CREDIT_TOKEN_ID );
		if ( '' === $credit_hash ) {
			$renewal->update_status( 'failed', __( 'PAYUNi renewal failed: no agreed card is linked to this subscription.', 'moksa-for-woocommerce' ) );
			return;
		}

		$result = PaymentRequest::charge_token( $renewal, $credit_hash, WC()->api_request_url( 'moksafowo_payuni_payment' ) );
		$info   = $result['info'];
		$state  = (string) ( $info['TradeStatus'] ?? '' );

		// 只有「有驗章、解得開」的回應才能據以判定。已付款照通知流程入帳。
		if ( $result['verified'] && TradeStatus::PAID === $state ) {
			PaymentResponse::apply_backend_result( $renewal, $info );
			return;
		}

		// 確定失敗：交易明確回付款失敗／取消，或外層是 PAYUNi 回的錯誤代碼（不是 SUCCESS、UNKNOWN、UNAPPROVED）。
		$outer_error = $result['verified'] && ! in_array( $result['status'], [ 'SUCCESS', 'UNKNOWN', 'UNAPPROVED' ], true );
		if ( ( $result['verified'] && in_array( $state, [ TradeStatus::FAIL, TradeStatus::CANCEL ], true ) ) || $outer_error ) {
			$renewal->update_status(
				'failed',
				sprintf(
					/* translators: 1: PAYUNi status code, 2: message */
					__( 'The PAYUNi renewal charge failed (%1$s): %2$s', 'moksa-for-woocommerce' ),
					$result['status'],
					$result['message']
				)
			);
			return;
		}

		// 其餘都是結果不明（UNKNOWN、待確認、502／HTML 錯誤頁、驗章失敗）：PAYUNi 可能已扣款。
		// 判失敗的話 WCS 重試會換新的 MerTradeNo 再扣一次，所以保留，等通知或 15 分鐘後查單（官方建議）。
		$renewal->update_status(
			'on-hold',
			__( 'PAYUNi has not confirmed the renewal charge yet. It will be checked again automatically; do not charge the customer again until then.', 'moksa-for-woocommerce' )
		);
		self::schedule_check( $renewal->get_id(), 1 );
	}

	private static function schedule_check( int $order_id, int $attempt ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$args = [ $order_id, $attempt ];
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::CHECK_HOOK, $args, 'moksa-for-woocommerce' ) ) {
			return;
		}
		as_schedule_single_action( time() + ( 1 === $attempt ? 15 : 60 ) * MINUTE_IN_SECONDS, self::CHECK_HOOK, $args, 'moksa-for-woocommerce' );
	}

	/** 結果不明的續訂單：以交易查詢補判（/api/trade/query）。 */
	public static function check( $order_id, $attempt = 1 ): void {
		$renewal = wc_get_order( (int) $order_id );
		if ( ! $renewal instanceof \WC_Order || $renewal->is_paid() || ! $renewal->has_status( 'on-hold' ) || Credit::GATEWAY_ID !== $renewal->get_payment_method() ) {
			return;
		}
		$query = PaymentRequest::query( $renewal->get_id(), false );
		$state = is_array( $query ) ? (string) ( $query['TradeStatus'] ?? '' ) : '';
		if ( TradeStatus::PAID === $state || TradeStatus::FAIL === $state || TradeStatus::CANCEL === $state ) {
			PaymentResponse::apply_backend_result(
				$renewal,
				[
					'Status'      => 'SUCCESS',
					'Message'     => __( 'Confirmed by transaction lookup', 'moksa-for-woocommerce' ),
					'MerTradeNo'  => (string) $query['MerTradeNo'],
					'TradeNo'     => (string) $query['TradeNo'],
					'TradeStatus' => $state,
					'TradeAmt'    => (string) ( $query['TradeAmt'] ?? '' ),
					'PaymentType' => (string) ( $query['PaymentType'] ?? '1' ),
				]
			);
			return;
		}
		if ( (int) $attempt < 4 ) {
			self::schedule_check( $renewal->get_id(), (int) $attempt + 1 );
			return;
		}
		$renewal->add_order_note( __( 'PAYUNi still has not confirmed this renewal charge. Check it in the PAYUNi dashboard before charging the customer again.', 'moksa-for-woocommerce' ) );
	}

	/** 訂閱結束後，約定的卡沒有其他訂閱在用就向 PAYUNi 取消 Token（p41）。 */
	public static function revoke_on_end( $subscription ): void {
		if ( ! $subscription instanceof \WC_Order || Credit::GATEWAY_ID !== $subscription->get_payment_method() ) {
			return;
		}
		self::revoke( (string) $subscription->get_meta( OrderMeta::CREDIT_TOKEN_ID ), $subscription, $subscription->get_id() );
	}

	/** WPSubscription 訂閱取消後，約定的卡沒有其他訂閱在用就取消 Token。 */
	public static function revoke_on_wps_end( $subscription_id ): void {
		$subscription_id = (int) $subscription_id;
		if ( Credit::GATEWAY_ID !== WpSubscription::gateway_of( $subscription_id ) || WpSubscription::cancelled_by_failed_payment( $subscription_id ) ) {
			return;
		}
		$credit_hash = (string) get_post_meta( $subscription_id, OrderMeta::CREDIT_TOKEN_ID, true );
		if ( '' === $credit_hash || WpSubscription::credential_in_use( OrderMeta::CREDIT_TOKEN_ID, $credit_hash, $subscription_id ) || Subscriptions::credential_in_use( OrderMeta::CREDIT_TOKEN_ID, $credit_hash ) ) {
			return;
		}
		WpSubscription::note(
			$subscription_id,
			PaymentRequest::cancel_token( $credit_hash )
				? __( 'The agreed card was cancelled at PAYUNi because no subscription uses it any more.', 'moksa-for-woocommerce' )
				: __( 'The agreed card could not be cancelled at PAYUNi. Cancel it in the PAYUNi dashboard.', 'moksa-for-woocommerce' )
		);
	}

	public static function revoke_wps_replaced( $subscription_id, $old ): void {
		$old         = (array) $old;
		$credit_hash = (string) ( $old[ OrderMeta::CREDIT_TOKEN_ID ] ?? '' );
		if ( '' === $credit_hash || WpSubscription::credential_in_use( OrderMeta::CREDIT_TOKEN_ID, $credit_hash ) || Subscriptions::credential_in_use( OrderMeta::CREDIT_TOKEN_ID, $credit_hash ) ) {
			return;
		}
		PaymentRequest::cancel_token( $credit_hash );
	}

	public static function revoke_replaced( $subscription, $old ): void {
		$old = (array) $old;
		if ( $subscription instanceof \WC_Order && ! empty( $old[ OrderMeta::CREDIT_TOKEN_ID ] ) ) {
			self::revoke( (string) $old[ OrderMeta::CREDIT_TOKEN_ID ], $subscription, 0 );
		}
	}

	private static function revoke( string $credit_hash, \WC_Order $subscription, int $exclude ): void {
		if ( '' === $credit_hash || Subscriptions::credential_in_use( OrderMeta::CREDIT_TOKEN_ID, $credit_hash, $exclude ) ) {
			return;
		}
		$subscription->add_order_note(
			PaymentRequest::cancel_token( $credit_hash )
				? __( 'The agreed card was cancelled at PAYUNi because no subscription uses it any more.', 'moksa-for-woocommerce' )
				: __( 'The agreed card could not be cancelled at PAYUNi. Cancel it in the PAYUNi dashboard.', 'moksa-for-woocommerce' )
		);
	}

	public static function payment_meta( $meta, $subscription ) {
		$meta[ Credit::GATEWAY_ID ] = [
			'post_meta' => [
				OrderMeta::CREDIT_TOKEN_ID => [
					'value' => (string) $subscription->get_meta( OrderMeta::CREDIT_TOKEN_ID ),
					'label' => __( 'PAYUNi CreditHash', 'moksa-for-woocommerce' ),
				],
			],
		];
		return $meta;
	}

	/**
	 * @throws \Exception 沒填 CreditHash 時擋下儲存.
	 */
	public static function validate_payment_meta( $meta, $subscription ): void {
		if ( '' === trim( (string) ( $meta['post_meta'][ OrderMeta::CREDIT_TOKEN_ID ]['value'] ?? '' ) ) ) {
			throw new \Exception( esc_html__( 'Enter the PAYUNi CreditHash.', 'moksa-for-woocommerce' ) );
		}
	}

	/** 顧客重付失敗的續訂單時約定了新卡，之後的續扣改用這張卡。 */
	public static function update_failing_payment_method( $subscription, $renewal ): void {
		if ( ! $subscription instanceof \WC_Order || ! $renewal instanceof \WC_Order ) {
			return;
		}
		foreach ( self::CARD_META as $key ) {
			$value = (string) $renewal->get_meta( $key );
			if ( '' !== $value ) {
				$subscription->update_meta_data( $key, $value );
			}
		}
		$subscription->save();
	}
}
