<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Modules\Ecpay\Api\BindHandler;
use Moksafowo\Modules\Ecpay\Api\Ecpg;
use Moksafowo\Modules\Ecpay\Api\Helper;
use Moksafowo\Modules\Ecpay\Gateways\CreditBind;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions 續扣：以首期綁定的 BindCardID 呼叫 CreatePaymentWithCardID（35630）。
 *
 * 官方規定授權失敗後「請勿對該 BindCardID 進行再次授權」，連續失敗綠界可能暫停該卡授權；
 * 所以被拒後在訂閱上記一筆 ECPAY_BIND_DECLINED，之後的續扣（含 WCS 自動重試）直接判失敗，
 * 等顧客重付時綁新卡才清掉。
 */
final class SubscriptionRenewal {

	private const CARD_META = [ Keys::ECPAY_BIND_CARD_ID, Keys::ECPAY_BIND_MEMBER_ID, Keys::ECPAY_CARD_LAST4, Keys::ECPAY_BIND_DECLINED ];

	/** 10300066：交易付款結果待確認中。 */
	private const RTN_PENDING = 10300066;

	/**
	 * 35630 列出「卡片可能已失效或額度不足，應聯繫消費者重新綁定」的代碼；
	 * 只有這些才標記被拒、不再授權。其他錯誤（參數、時間戳、系統）照一般失敗處理，WCS 可重試。
	 */
	private const DECLINE_CODES = [ 10100248, 10100251, 10100252, 10100254, 10100255, 10100256 ];

	public static function boot(): void {
		if ( ! Subscriptions::active() ) {
			return;
		}
		add_action( 'woocommerce_scheduled_subscription_payment_' . CreditBind::GATEWAY_ID, [ self::class, 'charge' ], 10, 2 );
		add_filter( 'moksafowo_subscription_copied_meta_keys', [ self::class, 'copied_meta_keys' ] );
		add_filter( 'woocommerce_subscription_payment_meta', [ self::class, 'payment_meta' ], 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta_' . CreditBind::GATEWAY_ID, [ self::class, 'validate_payment_meta' ], 10, 2 );
		add_action( 'woocommerce_subscription_failing_payment_method_updated_' . CreditBind::GATEWAY_ID, [ self::class, 'update_failing_payment_method' ], 10, 2 );
		add_action( 'woocommerce_subscription_status_cancelled', [ self::class, 'revoke_on_end' ] );
		add_action( 'woocommerce_subscription_status_expired', [ self::class, 'revoke_on_end' ] );
		add_action( 'moksafowo_subscription_credentials_replaced', [ self::class, 'revoke_replaced' ], 10, 2 );
	}

	public static function copied_meta_keys( $keys ): array {
		return array_merge( (array) $keys, self::CARD_META );
	}

	public static function charge( $amount, $renewal ): void {
		if ( ! $renewal instanceof \WC_Order || ! $renewal->needs_payment() ) {
			return;
		}

		$bind_card_id = (string) $renewal->get_meta( Keys::ECPAY_BIND_CARD_ID );
		$member_id    = (string) $renewal->get_meta( Keys::ECPAY_BIND_MEMBER_ID );
		if ( '' === $bind_card_id || '' === $member_id ) {
			$renewal->update_status( 'failed', __( 'ECPay renewal failed: no saved card is linked to this subscription.', 'moksa-for-woocommerce' ) );
			return;
		}
		// 被拒標記可能只在訂閱上（WCS 重試用的是同一張續訂單，不會再從訂閱複製）。
		$declined = '' !== (string) $renewal->get_meta( Keys::ECPAY_BIND_DECLINED );
		foreach ( Subscriptions::subscriptions_for( $renewal ) as $subscription ) {
			$declined = $declined || (
				'' !== (string) $subscription->get_meta( Keys::ECPAY_BIND_DECLINED )
				&& $bind_card_id === (string) $subscription->get_meta( Keys::ECPAY_BIND_CARD_ID )
			);
		}
		if ( $declined ) {
			$renewal->update_status( 'failed', __( 'ECPay renewal skipped: this saved card was declined before, and ECPay does not allow charging it again. Ask the customer to pay this order and save a new card.', 'moksa-for-woocommerce' ) );
			return;
		}

		$mtn = Helper::generate_merchant_trade_no( $renewal->get_id() );
		$renewal->update_meta_data( Keys::ECPAY_MERCHANT_TRADE_NO, $mtn );
		$renewal->save();
		$resp     = Ecpg::pay_with_card_id(
			[
				'PlatformID'   => '',
				'MerchantID'   => Helper::merchant_id(),
				'BindCardID'   => $bind_card_id,
				'OrderInfo'    => [
					'MerchantTradeDate' => current_time( 'Y/m/d H:i:s' ),
					'MerchantTradeNo'   => $mtn,
					'TotalAmount'       => (int) round( (float) $renewal->get_total() ),
					'ReturnURL'         => home_url( '/wc-api/moksafowo_ecpay_bind_notify' ),
					'TradeDesc'         => BindHandler::item_name( $renewal ),
					'ItemName'          => BindHandler::item_name( $renewal ),
				],
				'ConsumerInfo' => BindHandler::consumer_info( $renewal, $member_id ),
				'Need3D'       => 0,
			]
		);
		$data     = $resp['data'];
		$rtn_code = (int) ( $data['RtnCode'] ?? 0 );

		// 連線失敗、綠界收到請求但回應解不開、回 1 卻不是已付款、或待確認：不知道有沒有扣到，
		// 先保留，避免重試時重複授權。
		$unreadable = 1 === $resp['trans_code'] && empty( $data );
		$odd        = $resp['ok'] && '1' !== (string) ( $data['OrderInfo']['TradeStatus'] ?? '' );
		if ( -1 === $resp['trans_code'] || $unreadable || $odd || self::RTN_PENDING === $rtn_code ) {
			$renewal->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: ECPay merchant trade number, 2: message */
					__( 'The ECPay renewal charge (trade number %1$s) is not confirmed yet: %2$s. Check it in the ECPay dashboard before charging again.', 'moksa-for-woocommerce' ),
					$mtn,
					$resp['message']
				)
			);
			return;
		}

		if ( $resp['ok'] && '1' === (string) ( $data['OrderInfo']['TradeStatus'] ?? '' ) ) {
			BindHandler::apply_result( $renewal, $data );
			return;
		}

		if ( ! in_array( $rtn_code, self::DECLINE_CODES, true ) ) {
			$renewal->update_status(
				'failed',
				sprintf(
					/* translators: 1: ECPay message, 2: code */
					__( 'The ECPay renewal charge failed: %1$s (code %2$d).', 'moksa-for-woocommerce' ),
					$resp['message'],
					$rtn_code
				)
			);
			return;
		}

		// 卡片被拒：續訂單與訂閱都記下，之後（含 WCS 對同一張續訂單的重試）不再對這張卡授權。
		$renewal->update_meta_data( Keys::ECPAY_BIND_DECLINED, current_time( 'mysql' ) );
		$renewal->save();
		foreach ( Subscriptions::subscriptions_for( $renewal ) as $subscription ) {
			$subscription->update_meta_data( Keys::ECPAY_BIND_DECLINED, current_time( 'mysql' ) );
			$subscription->save();
		}
		$renewal->update_status(
			'failed',
			sprintf(
				/* translators: 1: ECPay message, 2: code */
				__( 'The ECPay renewal charge was declined: %1$s (code %2$d). This card will not be charged again; ask the customer to pay this order and save a new card.', 'moksa-for-woocommerce' ),
				$resp['message'],
				$rtn_code
			)
		);
	}

	/** 訂閱結束後，綁定卡沒有其他訂閱在用就刪除（每位會員最多只能綁 5 張，35591）。 */
	public static function revoke_on_end( $subscription ): void {
		if ( ! $subscription instanceof \WC_Order || CreditBind::GATEWAY_ID !== $subscription->get_payment_method() ) {
			return;
		}
		self::revoke( (string) $subscription->get_meta( Keys::ECPAY_BIND_CARD_ID ), $subscription, $subscription->get_id() );
	}

	public static function revoke_replaced( $subscription, $old ): void {
		$old = (array) $old;
		if ( $subscription instanceof \WC_Order && ! empty( $old[ Keys::ECPAY_BIND_CARD_ID ] ) ) {
			self::revoke( (string) $old[ Keys::ECPAY_BIND_CARD_ID ], $subscription, 0 );
		}
	}

	private static function revoke( string $bind_card_id, \WC_Order $subscription, int $exclude ): void {
		if ( '' === $bind_card_id || Subscriptions::credential_in_use( Keys::ECPAY_BIND_CARD_ID, $bind_card_id, $exclude ) ) {
			return;
		}
		$resp = Ecpg::delete_bind_card( $bind_card_id );
		$subscription->add_order_note(
			$resp['ok']
				? __( 'The saved card was deleted from ECPay because no subscription uses it any more.', 'moksa-for-woocommerce' )
				/* translators: %s: ECPay message */
				: sprintf( __( 'The saved card could not be deleted from ECPay: %s', 'moksa-for-woocommerce' ), $resp['message'] )
		);
	}

	public static function payment_meta( $meta, $subscription ) {
		$meta[ CreditBind::GATEWAY_ID ] = [
			'post_meta' => [
				Keys::ECPAY_BIND_CARD_ID   => [
					'value' => (string) $subscription->get_meta( Keys::ECPAY_BIND_CARD_ID ),
					'label' => __( 'ECPay saved card ID (BindCardID)', 'moksa-for-woocommerce' ),
				],
				Keys::ECPAY_BIND_MEMBER_ID => [
					'value' => (string) $subscription->get_meta( Keys::ECPAY_BIND_MEMBER_ID ),
					'label' => __( 'ECPay member ID (MerchantMemberID)', 'moksa-for-woocommerce' ),
				],
			],
		];
		return $meta;
	}

	/**
	 * @throws \Exception 缺少綁卡資料時擋下儲存.
	 */
	public static function validate_payment_meta( $meta, $subscription ): void {
		foreach ( [ Keys::ECPAY_BIND_CARD_ID, Keys::ECPAY_BIND_MEMBER_ID ] as $key ) {
			if ( '' === trim( (string) ( $meta['post_meta'][ $key ]['value'] ?? '' ) ) ) {
				throw new \Exception( esc_html__( 'Enter both the ECPay saved card ID and member ID.', 'moksa-for-woocommerce' ) );
			}
		}
		// 商家手動換了卡，就不再沿用舊卡的被拒紀錄。
		if ( $subscription instanceof \WC_Order && (string) $subscription->get_meta( Keys::ECPAY_BIND_CARD_ID ) !== (string) $meta['post_meta'][ Keys::ECPAY_BIND_CARD_ID ]['value'] ) {
			$subscription->update_meta_data( Keys::ECPAY_BIND_DECLINED, '' );
		}
	}

	public static function update_failing_payment_method( $subscription, $renewal ): void {
		if ( ! $subscription instanceof \WC_Order || ! $renewal instanceof \WC_Order ) {
			return;
		}
		foreach ( self::CARD_META as $key ) {
			$subscription->update_meta_data( $key, (string) $renewal->get_meta( $key ) );
		}
		$subscription->save();
	}
}
