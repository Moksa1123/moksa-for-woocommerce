<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Modules\Tappay\Api;
use Moksafowo\Modules\Tappay\Api\Client;
use Moksafowo\Modules\Tappay\Api\Helper;
use Moksafowo\Modules\Tappay\Gateways\Credit;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions 續扣：WCS 排程到期時，用首期記住的卡片向 TapPay 扣款。
 */
final class SubscriptionRenewal {

	private const CARD_META = [ Keys::TAPPAY_CARD_KEY, Keys::TAPPAY_CARD_TOKEN, Keys::TAPPAY_CARD_LAST4 ];

	public static function boot(): void {
		if ( ! Subscriptions::active() ) {
			return;
		}
		add_action( 'woocommerce_scheduled_subscription_payment_' . Credit::GATEWAY_ID, [ self::class, 'charge' ], 10, 2 );
		add_filter( 'moksafowo_subscription_copied_meta_keys', [ self::class, 'copied_meta_keys' ] );
		add_filter( 'woocommerce_subscription_payment_meta', [ self::class, 'payment_meta' ], 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta_' . Credit::GATEWAY_ID, [ self::class, 'validate_payment_meta' ], 10, 2 );
		add_action( 'woocommerce_subscription_failing_payment_method_updated_' . Credit::GATEWAY_ID, [ self::class, 'update_failing_payment_method' ], 10, 2 );
		add_action( 'woocommerce_subscription_status_cancelled', [ self::class, 'revoke_on_end' ] );
		add_action( 'woocommerce_subscription_status_expired', [ self::class, 'revoke_on_end' ] );
		add_action( 'moksafowo_subscription_credentials_replaced', [ self::class, 'revoke_replaced' ], 10, 2 );
	}

	/** 3D 驗證通過、訂單付款確認後，才把暫存的卡片憑證寫進訂閱。 */
	public static function promote_pending_card( \WC_Order $order ): void {
		$key   = (string) $order->get_meta( Keys::TAPPAY_PENDING_CARD_KEY );
		$token = (string) $order->get_meta( Keys::TAPPAY_PENDING_CARD_TOKEN );
		if ( '' === $key || '' === $token ) {
			return;
		}
		$order->delete_meta_data( Keys::TAPPAY_PENDING_CARD_KEY );
		$order->delete_meta_data( Keys::TAPPAY_PENDING_CARD_TOKEN );
		Subscriptions::store_payment_meta(
			$order,
			[
				Keys::TAPPAY_CARD_KEY   => $key,
				Keys::TAPPAY_CARD_TOKEN => $token,
			]
		);
	}

	/** 訂閱結束後，卡片沒有其他訂閱在用就向 TapPay 刪除（Remove Card）。 */
	public static function revoke_on_end( $subscription ): void {
		if ( ! $subscription instanceof \WC_Order || Credit::GATEWAY_ID !== $subscription->get_payment_method() ) {
			return;
		}
		self::revoke( (string) $subscription->get_meta( Keys::TAPPAY_CARD_KEY ), (string) $subscription->get_meta( Keys::TAPPAY_CARD_TOKEN ), $subscription );
	}

	public static function revoke_replaced( $subscription, $old ): void {
		$old = (array) $old;
		if ( ! $subscription instanceof \WC_Order || empty( $old[ Keys::TAPPAY_CARD_TOKEN ] ) ) {
			return;
		}
		$key = (string) ( $old[ Keys::TAPPAY_CARD_KEY ] ?? $subscription->get_meta( Keys::TAPPAY_CARD_KEY ) );
		self::revoke( $key, (string) $old[ Keys::TAPPAY_CARD_TOKEN ], $subscription, true );
	}

	private static function revoke( string $card_key, string $card_token, \WC_Order $subscription, bool $replaced = false ): void {
		if ( '' === $card_key || '' === $card_token ) {
			return;
		}
		$exclude = $replaced ? 0 : $subscription->get_id();
		if ( Subscriptions::credential_in_use( Keys::TAPPAY_CARD_TOKEN, $card_token, $exclude ) ) {
			return;
		}
		$resp = Client::remove_card( $card_key, $card_token );
		$subscription->add_order_note(
			$resp['ok']
				? __( 'The saved TapPay card was removed from TapPay because no subscription uses it any more.', 'moksa-for-woocommerce' )
				/* translators: %s: TapPay message */
				: sprintf( __( 'The saved TapPay card could not be removed from TapPay: %s', 'moksa-for-woocommerce' ), $resp['msg'] )
		);
	}

	public static function copied_meta_keys( $keys ): array {
		return array_merge( (array) $keys, self::CARD_META );
	}

	public static function charge( $amount, $renewal ): void {
		if ( ! $renewal instanceof \WC_Order || ! $renewal->needs_payment() ) {
			return;
		}

		$card_key   = (string) $renewal->get_meta( Keys::TAPPAY_CARD_KEY );
		$card_token = (string) $renewal->get_meta( Keys::TAPPAY_CARD_TOKEN );
		if ( '' === $card_key || '' === $card_token ) {
			$renewal->update_status( 'failed', __( 'TapPay renewal failed: no saved card is linked to this subscription.', 'moksa-for-woocommerce' ) );
			return;
		}

		$retry        = '' !== (string) $renewal->get_meta( Keys::TAPPAY_ORDER_NUMBER );
		$order_number = Helper::build_order_number( $renewal, $retry );
		$amt          = (int) round( (float) $amount );

		$resp = Client::pay_by_token(
			[
				'card_key'                          => $card_key,
				'card_token'                        => $card_token,
				'partner_key'                       => Helper::partner_key(),
				'merchant_id'                       => Helper::merchant_id(),
				'amount'                            => $amt,
				'currency'                          => 'TWD',
				'order_number'                      => $order_number,
				'details'                           => Credit::build_details( $renewal ),
				'three_domain_secure'               => false,
				'is_merchant_initiated_transaction' => true,
			]
		);
		Helper::log(
			'pay-by-token',
			[
				'order_id'     => $renewal->get_id(),
				'order_number' => $order_number,
				'status'       => $resp['status'],
				'msg'          => $resp['msg'],
			]
		);

		$renewal->update_meta_data( Keys::TAPPAY_ORDER_NUMBER, $order_number );
		$renewal->update_meta_data( Keys::TAPPAY_TRANSACTION_STATUS, (string) $resp['status'] );
		$renewal->save();

		// status -1 是連線失敗或回應讀不到，TapPay 可能已經扣款：用這次的 order_number 查交易紀錄。
		// 查到成功就當成功；確定失敗才判失敗；其餘（查不到、待付款、查詢也失敗）一律保留並排程再查，
		// 不能判失敗，否則 WCS 重試會換新 order_number 再扣一次。
		if ( -1 === (int) $resp['status'] ) {
			$query  = Client::query_by_order_number( $order_number );
			$record = (array) ( $query['data']['trade_records'][0] ?? [] );
			$state  = (string) ( $record['record_status'] ?? '' );
			if ( $query['ok'] && in_array( $state, [ '0', '1' ], true ) ) {
				$resp = [
					'ok'     => true,
					'status' => 0,
					'msg'    => '',
					'data'   => $record,
				];
			} elseif ( ! $query['ok'] || ! in_array( $state, [ '-1', '5' ], true ) ) {
				$renewal->update_status(
					'on-hold',
					sprintf(
						/* translators: %s: TapPay order number */
						__( 'The TapPay renewal charge (order number %s) could not be confirmed. It will be checked again automatically; do not charge the customer again until then.', 'moksa-for-woocommerce' ),
						$order_number
					)
				);
				Api\IpnHandler::schedule_pending_check( $renewal );
				return;
			}
		}

		if ( ! $resp['ok'] ) {
			$renewal->update_status(
				'failed',
				sprintf(
					/* translators: 1: status code, 2: message */
					__( 'The TapPay renewal charge failed (status code %1$d): %2$s', 'moksa-for-woocommerce' ),
					$resp['status'],
					$resp['msg']
				)
			);
			return;
		}

		$data         = (array) $resp['data'];
		$rec_trade_id = (string) ( $data['rec_trade_id'] ?? '' );
		$fields       = [
			Keys::TAPPAY_REC_TRADE_ID        => $rec_trade_id,
			Keys::TAPPAY_BANK_TRANSACTION_ID => (string) ( $data['bank_transaction_id'] ?? '' ),
			Keys::TAPPAY_AUTH_CODE           => (string) ( $data['auth_code'] ?? '' ),
			Keys::TAPPAY_CARD_LAST4          => (string) ( $data['card_info']['last_four'] ?? '' ),
		];
		foreach ( $fields as $key => $value ) {
			if ( '' !== $value ) {
				$renewal->update_meta_data( $key, $value );
			}
		}
		$renewal->update_meta_data( Keys::TAPPAY_THREE_DOMAIN_SECURE, 'no' );
		$renewal->save();
		$renewal->payment_complete( $rec_trade_id );
		$renewal->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: rec_trade_id */
				__( 'TapPay renewal charge of NT$%1$s completed — transaction ID %2$s', 'moksa-for-woocommerce' ),
				$amt,
				$rec_trade_id
			)
		);
	}

	/**
	 * 後台訂閱編輯頁可以看到、改寫記住的卡片憑證。
	 */
	public static function payment_meta( $meta, $subscription ) {
		$meta[ Credit::GATEWAY_ID ] = [
			'post_meta' => [
				Keys::TAPPAY_CARD_KEY   => [
					'value' => (string) $subscription->get_meta( Keys::TAPPAY_CARD_KEY ),
					'label' => __( 'TapPay card key', 'moksa-for-woocommerce' ),
				],
				Keys::TAPPAY_CARD_TOKEN => [
					'value' => (string) $subscription->get_meta( Keys::TAPPAY_CARD_TOKEN ),
					'label' => __( 'TapPay card token', 'moksa-for-woocommerce' ),
				],
			],
		];
		return $meta;
	}

	/**
	 * @throws \Exception 缺少卡片憑證時擋下儲存.
	 */
	public static function validate_payment_meta( $meta, $subscription ): void {
		$post_meta = (array) ( $meta['post_meta'] ?? [] );
		foreach ( [ Keys::TAPPAY_CARD_KEY, Keys::TAPPAY_CARD_TOKEN ] as $key ) {
			if ( '' === trim( (string) ( $post_meta[ $key ]['value'] ?? '' ) ) ) {
				throw new \Exception( esc_html__( 'Enter both the TapPay card key and card token.', 'moksa-for-woocommerce' ) );
			}
		}
	}

	/** 顧客用新卡付了失敗的續訂單後，之後的續扣改用這張卡。 */
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
