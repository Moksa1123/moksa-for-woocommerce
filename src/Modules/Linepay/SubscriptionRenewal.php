<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Linepay;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Modules\Linepay\Api\Currency;
use Moksafowo\Modules\Linepay\Api\PaymentRequest;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions 續扣：WCS 排程到期時，用首期授權取得的 regKey 走 LINE Pay 自動付款。
 */
final class SubscriptionRenewal {

	public const CHECK_HOOK = 'moksafowo_linepay_renewal_check';

	/** 官方：回這些代碼表示自動付款金鑰已作廢，要請顧客重新授權。另加 1190（regKey 不存在）、1193（已過期）。 */
	private const DEAD_KEY_CODES = [ '1141', '1190', '1193', '1282', '1283', '1284', '1285', '1286', '1287', '1290', '1291', '1292', '1293', '1294', '1295' ];

	public static function boot(): void {
		if ( ! Subscriptions::active() ) {
			return;
		}
		add_action( 'woocommerce_scheduled_subscription_payment_' . Constants::ID, [ self::class, 'charge' ], 10, 2 );
		add_filter( 'moksafowo_subscription_copied_meta_keys', [ self::class, 'copied_meta_keys' ] );
		add_filter( 'woocommerce_subscription_payment_meta', [ self::class, 'payment_meta' ], 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta_' . Constants::ID, [ self::class, 'validate_payment_meta' ], 10, 2 );
		add_action( 'woocommerce_subscription_failing_payment_method_updated_' . Constants::ID, [ self::class, 'update_failing_payment_method' ], 10, 2 );
		add_action( self::CHECK_HOOK, [ self::class, 'check' ], 10, 2 );
		add_action( 'woocommerce_subscription_status_cancelled', [ self::class, 'revoke_on_end' ] );
		add_action( 'woocommerce_subscription_status_expired', [ self::class, 'revoke_on_end' ] );
		add_action( 'moksafowo_subscription_credentials_replaced', [ self::class, 'revoke_replaced' ], 10, 2 );
	}

	public static function copied_meta_keys( $keys ): array {
		return array_merge( (array) $keys, [ Keys::LINEPAY_REGKEY ] );
	}

	public static function charge( $amount, $renewal ): void {
		if ( ! $renewal instanceof \WC_Order || ! $renewal->needs_payment() ) {
			return;
		}

		$reg_key = (string) $renewal->get_meta( Keys::LINEPAY_REGKEY );
		$gateway = self::gateway();
		if ( '' === $reg_key || ! $gateway ) {
			$renewal->update_status( 'failed', __( 'LINE Pay renewal failed: no automatic payment key is linked to this subscription.', 'moksa-for-woocommerce' ) );
			return;
		}

		// 同一張續訂單沿用同一個 orderId，LINE Pay 會以 1172（orderId 重複）擋掉重送；
		// 只有確定失敗後才換下一個，免得結果不明時重試等於再扣一次。
		$order_ref = self::order_ref( $renewal );

		try {
			$result = ( new PaymentRequest( $gateway ) )->preapproved_pay( $renewal, $reg_key, $order_ref );
		} catch ( \Exception $e ) {
			LinePay::log( sprintf( '[preapproved][order_id:%s] error: %s', $renewal->get_id(), $e->getMessage() ), 'error' );
			self::hold_for_check( $renewal, $order_ref );
			return;
		}

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- LINE Pay API 回應欄位.
		$code    = (string) ( $result->returnCode ?? '' );
		$message = (string) ( $result->returnMessage ?? '' );
		if ( '0000' === $code ) {
			self::complete( $renewal, (string) ( $result->info->transactionId ?? '' ) );
			return;
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// 1172：這個 orderId 已經送過（前一次結果不明），以明細查詢為準。9000：LINE Pay 內部錯誤，結果不明。
		if ( '1172' === $code || '9000' === $code || '' === $code ) {
			self::hold_for_check( $renewal, $order_ref );
			return;
		}

		self::next_order_ref( $renewal );
		if ( in_array( $code, self::DEAD_KEY_CODES, true ) ) {
			// 金鑰已被 LINE Pay 作廢（官方：1141、1282–1287、1290–1295），之後都不能再用。
			foreach ( Subscriptions::subscriptions_for( $renewal ) as $subscription ) {
				if ( $reg_key === (string) $subscription->get_meta( Keys::LINEPAY_REGKEY ) ) {
					$subscription->update_meta_data( Keys::LINEPAY_REGKEY, '' );
					$subscription->save();
				}
			}
			$renewal->update_meta_data( Keys::LINEPAY_REGKEY, '' );
			$renewal->save();
			$renewal->update_status(
				'failed',
				sprintf(
					/* translators: 1: LINE Pay return code, 2: message */
					__( 'LINE Pay rejected the automatic payment key (return code %1$s): %2$s. Ask the customer to pay this order with LINE Pay again to authorize a new key.', 'moksa-for-woocommerce' ),
					$code,
					$message
				)
			);
			return;
		}

		$renewal->update_status(
			'failed',
			sprintf(
				/* translators: 1: LINE Pay return code, 2: message */
				__( 'The LINE Pay renewal charge failed (return code %1$s): %2$s', 'moksa-for-woocommerce' ),
				$code,
				$message
			)
		);
	}

	/** 結果不明的續訂單：以 orderId 查付款明細補判。 */
	public static function check( $order_id, $attempt = 1 ): void {
		$renewal = wc_get_order( (int) $order_id );
		$gateway = self::gateway();
		if ( ! $renewal instanceof \WC_Order || ! $gateway || $renewal->is_paid() || ! $renewal->has_status( 'on-hold' ) || Constants::ID !== $renewal->get_payment_method() ) {
			return;
		}
		$order_ref = self::order_ref( $renewal );
		$reached   = true;
		try {
			$found = ( new PaymentRequest( $gateway ) )->find_payment_by_order_ref( $order_ref );
		} catch ( \Exception $e ) {
			$found   = null;
			$reached = false;
		}
		if ( null !== $found ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- LINE Pay API 回應欄位.
			self::complete( $renewal, (string) $found->transactionId );
			return;
		}
		if ( (int) $attempt < 4 ) {
			self::schedule_check( $renewal->get_id(), (int) $attempt + 1 );
			return;
		}
		if ( ! $reached ) {
			// 連不上 LINE Pay 不等於沒扣款：不換 orderId，請商家自己確認。
			$renewal->add_order_note(
				sprintf(
					/* translators: %s: LINE Pay order ID */
					__( 'LINE Pay could not be reached to check order ID %s. Check the payment in the LINE Pay merchant center before charging the customer again.', 'moksa-for-woocommerce' ),
					$order_ref
				)
			);
			return;
		}
		$renewal->add_order_note(
			sprintf(
				/* translators: %s: LINE Pay order ID */
				__( 'LINE Pay has no payment for order ID %s. If the customer was not charged, change this order to Failed so the subscription can retry.', 'moksa-for-woocommerce' ),
				$order_ref
			)
		);
		self::next_order_ref( $renewal );
	}

	private static function complete( \WC_Order $renewal, string $transaction_id ): void {
		$renewal->update_meta_data( Keys::LINEPAY_RESERVED_TXN_ID, $transaction_id );
		$renewal->update_meta_data( Keys::LINEPAY_PAYMENT_STATUS, Constants::PAYMENT_STATUS_CONFIRMED );
		$renewal->update_meta_data( Keys::LINEPAY_BALANCED_AMOUNT, Currency::get_standardized( $renewal->get_total(), $renewal->get_currency() ) );
		$renewal->update_meta_data( Keys::LINEPAY_PAY_TYPE, Constants::PAYMENT_TYPE_PREAPPROVED );
		$renewal->save();
		$renewal->payment_complete( $transaction_id );
		$renewal->add_order_note(
			sprintf(
				/* translators: %s: LINE Pay transaction ID */
				__( 'LINE Pay renewal charge completed — transaction ID %s', 'moksa-for-woocommerce' ),
				$transaction_id
			)
		);
	}

	private static function hold_for_check( \WC_Order $renewal, string $order_ref ): void {
		$renewal->update_status(
			'on-hold',
			sprintf(
				/* translators: %s: LINE Pay order ID */
				__( 'The LINE Pay renewal charge (order ID %s) could not be confirmed. It will be checked again automatically; do not charge the customer again until then.', 'moksa-for-woocommerce' ),
				$order_ref
			)
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
		as_schedule_single_action( time() + ( 1 === $attempt ? 10 : 60 ) * MINUTE_IN_SECONDS, self::CHECK_HOOK, $args, 'moksa-for-woocommerce' );
	}

	private static function order_ref( \WC_Order $renewal ): string {
		$attempt = max( 1, (int) $renewal->get_meta( Keys::LINEPAY_RENEWAL_ATTEMPT ) );
		if ( 0 === (int) $renewal->get_meta( Keys::LINEPAY_RENEWAL_ATTEMPT ) ) {
			$renewal->update_meta_data( Keys::LINEPAY_RENEWAL_ATTEMPT, $attempt );
			$renewal->save();
		}
		return $renewal->get_id() . '-R' . $attempt;
	}

	private static function next_order_ref( \WC_Order $renewal ): void {
		$renewal->update_meta_data( Keys::LINEPAY_RENEWAL_ATTEMPT, max( 1, (int) $renewal->get_meta( Keys::LINEPAY_RENEWAL_ATTEMPT ) ) + 1 );
		$renewal->save();
	}

	private static function gateway() {
		return WC()->payment_gateways()->payment_gateways()[ Constants::ID ] ?? null;
	}

	/** 訂閱結束後，金鑰沒有其他訂閱在用就向 LINE Pay 註銷（官方：停止服務時應呼叫 expire）。 */
	public static function revoke_on_end( $subscription ): void {
		if ( ! $subscription instanceof \WC_Order || Constants::ID !== $subscription->get_payment_method() ) {
			return;
		}
		self::revoke( (string) $subscription->get_meta( Keys::LINEPAY_REGKEY ), $subscription, $subscription->get_id() );
	}

	public static function revoke_replaced( $subscription, $old ): void {
		$old = (array) $old;
		if ( $subscription instanceof \WC_Order && ! empty( $old[ Keys::LINEPAY_REGKEY ] ) ) {
			self::revoke( (string) $old[ Keys::LINEPAY_REGKEY ], $subscription, 0 );
		}
	}

	private static function revoke( string $reg_key, \WC_Order $subscription, int $exclude ): void {
		$gateway = self::gateway();
		if ( '' === $reg_key || ! $gateway || Subscriptions::credential_in_use( Keys::LINEPAY_REGKEY, $reg_key, $exclude ) ) {
			return;
		}
		$subscription->add_order_note(
			( new PaymentRequest( $gateway ) )->expire_regkey( $reg_key )
				? __( 'The LINE Pay automatic payment key was discarded because no subscription uses it any more.', 'moksa-for-woocommerce' )
				: __( 'The LINE Pay automatic payment key could not be discarded. It can be removed in the LINE Pay merchant center.', 'moksa-for-woocommerce' )
		);
	}

	public static function payment_meta( $meta, $subscription ) {
		$meta[ Constants::ID ] = [
			'post_meta' => [
				Keys::LINEPAY_REGKEY => [
					'value' => (string) $subscription->get_meta( Keys::LINEPAY_REGKEY ),
					'label' => __( 'LINE Pay automatic payment key (regKey)', 'moksa-for-woocommerce' ),
				],
			],
		];
		return $meta;
	}

	/**
	 * @throws \Exception 沒填 regKey 時擋下儲存.
	 */
	public static function validate_payment_meta( $meta, $subscription ): void {
		if ( '' === trim( (string) ( $meta['post_meta'][ Keys::LINEPAY_REGKEY ]['value'] ?? '' ) ) ) {
			throw new \Exception( esc_html__( 'Enter the LINE Pay automatic payment key (regKey).', 'moksa-for-woocommerce' ) );
		}
	}

	/** 顧客重付失敗的續訂單時重新授權，之後的續扣改用新的 regKey。 */
	public static function update_failing_payment_method( $subscription, $renewal ): void {
		if ( ! $subscription instanceof \WC_Order || ! $renewal instanceof \WC_Order ) {
			return;
		}
		$reg_key = (string) $renewal->get_meta( Keys::LINEPAY_REGKEY );
		if ( '' !== $reg_key ) {
			$subscription->update_meta_data( Keys::LINEPAY_REGKEY, $reg_key );
			$subscription->save();
		}
	}
}
