<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Modules\ShoplinePayments\Api\Client;
use Moksafowo\Modules\ShoplinePayments\Api\Helper;
use Moksafowo\Modules\ShoplinePayments\Gateways\CardGateway;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions 續扣：WCS 排程到期時，用首期綁定的付款工具建立定期扣款（Recurring）交易。
 */
final class SubscriptionRenewal {

	private const CARD_META = [ Keys::SLP_CUSTOMER_ID, Keys::SLP_INSTRUMENT_ID, Keys::SLP_CARD_LAST4 ];

	public static function boot(): void {
		if ( ! Subscriptions::active() ) {
			return;
		}
		add_action( 'woocommerce_scheduled_subscription_payment_' . CardGateway::GATEWAY_ID, [ self::class, 'charge' ], 10, 2 );
		add_filter( 'moksafowo_subscription_copied_meta_keys', [ self::class, 'copied_meta_keys' ] );
		add_filter( 'woocommerce_subscription_payment_meta', [ self::class, 'payment_meta' ], 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta_' . CardGateway::GATEWAY_ID, [ self::class, 'validate_payment_meta' ], 10, 2 );
		add_action( 'woocommerce_subscription_failing_payment_method_updated_' . CardGateway::GATEWAY_ID, [ self::class, 'update_failing_payment_method' ], 10, 2 );
		add_action( 'woocommerce_subscription_status_cancelled', [ self::class, 'revoke_on_end' ] );
		add_action( 'woocommerce_subscription_status_expired', [ self::class, 'revoke_on_end' ] );
		add_action( 'moksafowo_subscription_credentials_replaced', [ self::class, 'revoke_replaced' ], 10, 2 );
	}

	/** 訂閱結束後，付款工具沒有其他訂閱在用就向 Shopline 解綁。 */
	public static function revoke_on_end( $subscription ): void {
		if ( ! $subscription instanceof \WC_Order || CardGateway::GATEWAY_ID !== $subscription->get_payment_method() ) {
			return;
		}
		self::revoke( (string) $subscription->get_meta( Keys::SLP_CUSTOMER_ID ), (string) $subscription->get_meta( Keys::SLP_INSTRUMENT_ID ), $subscription, $subscription->get_id() );
	}

	public static function revoke_replaced( $subscription, $old ): void {
		$old = (array) $old;
		if ( ! $subscription instanceof \WC_Order || empty( $old[ Keys::SLP_INSTRUMENT_ID ] ) ) {
			return;
		}
		$customer_id = (string) ( $old[ Keys::SLP_CUSTOMER_ID ] ?? $subscription->get_meta( Keys::SLP_CUSTOMER_ID ) );
		self::revoke( $customer_id, (string) $old[ Keys::SLP_INSTRUMENT_ID ], $subscription, 0 );
	}

	private static function revoke( string $customer_id, string $instrument_id, \WC_Order $subscription, int $exclude ): void {
		if ( '' === $customer_id || '' === $instrument_id || Subscriptions::credential_in_use( Keys::SLP_INSTRUMENT_ID, $instrument_id, $exclude ) ) {
			return;
		}
		$resp = Client::unbind_instrument( $customer_id, $instrument_id );
		$subscription->add_order_note(
			$resp['ok']
				? __( 'The saved card was unbound from Shopline Payments because no subscription uses it any more.', 'moksa-for-woocommerce' )
				/* translators: %s: error message */
				: sprintf( __( 'The saved card could not be unbound from Shopline Payments: %s', 'moksa-for-woocommerce' ), $resp['message'] )
		);
	}

	public static function copied_meta_keys( $keys ): array {
		return array_merge( (array) $keys, self::CARD_META );
	}

	public static function charge( $amount, $renewal ): void {
		if ( ! $renewal instanceof \WC_Order || ! $renewal->needs_payment() ) {
			return;
		}

		$customer_id   = (string) $renewal->get_meta( Keys::SLP_CUSTOMER_ID );
		$instrument_id = (string) $renewal->get_meta( Keys::SLP_INSTRUMENT_ID );
		// 官方建議扣款前先查付款工具狀態；首期通知沒帶付款工具 ID 時也從這裡補。
		$usable = '' !== $customer_id ? self::usable_instrument( $customer_id, $instrument_id ) : '';
		if ( '' === $usable ) {
			$renewal->update_status( 'failed', __( 'Shopline Payments renewal failed: the saved card is missing, expired or no longer usable. Ask the customer to pay with a new card.', 'moksa-for-woocommerce' ) );
			return;
		}
		if ( $usable !== $instrument_id ) {
			$renewal->update_meta_data( Keys::SLP_INSTRUMENT_ID, $usable );
			foreach ( Subscriptions::subscriptions_for( $renewal ) as $subscription ) {
				$subscription->update_meta_data( Keys::SLP_INSTRUMENT_ID, $usable );
				$subscription->save();
			}
		}

		$retry        = '' !== (string) $renewal->get_meta( Keys::SLP_REFERENCE_ID );
		$reference_id = Helper::build_reference_id( $renewal->get_id(), $retry );
		$gateway      = WC()->payment_gateways()->payment_gateways()[ CardGateway::GATEWAY_ID ] ?? null;
		$builder      = $gateway instanceof CardGateway ? $gateway : new CardGateway();

		$body = [
			'acquirerType'     => 'SDK',
			'referenceOrderId' => $reference_id,
			'amount'           => [
				'value'    => (int) round( (float) $renewal->get_total() * 100 ),
				'currency' => 'TWD',
			],
			'language'         => 'zh-TW',
			'returnUrl'        => $renewal->get_checkout_order_received_url(),
			// 定期扣款不經 SDK，但欄位不可省略。型別是 JSON 字串：送空物件會回 1004 參數錯誤（沙箱實測）。
			'paySession'       => '{}',
			'confirm'          => [
				'paymentMethod'     => 'CreditCard',
				'paymentBehavior'   => 'Recurring',
				'autoConfirm'       => true,
				'paymentCustomerId' => $customer_id,
				'paymentInstrument' => [ 'paymentInstrumentId' => $usable ],
			],
			'order'            => $builder->trade_order( $renewal ),
			'billing'          => $builder->trade_billing( $renewal ),
			'customer'         => $builder->trade_customer( $renewal ),
			'client'           => [ 'ip' => self::server_ip() ],
		];

		$resp = Client::create_payment( $body, Helper::idempotent_key( 'recurring-' . $reference_id ) );
		$data = (array) $resp['data'];
		Helper::log(
			'recurring create',
			[
				'order_id'     => $renewal->get_id(),
				'reference_id' => $reference_id,
				'ok'           => $resp['ok'],
				'code'         => $resp['code'],
				'status'       => (string) ( $data['status'] ?? '' ),
			]
		);

		$renewal->update_meta_data( Keys::SLP_REFERENCE_ID, $reference_id );
		$renewal->update_meta_data( Keys::SLP_PAYMENT_METHOD, 'CreditCard' );
		$status = (string) ( $data['status'] ?? '' );
		if ( '' !== $status ) {
			$renewal->update_meta_data( Keys::SLP_STATUS, $status );
		}
		$trade_order_id = (string) ( $data['tradeOrderId'] ?? '' );
		if ( '' !== $trade_order_id ) {
			$renewal->update_meta_data( Keys::SLP_TRADE_ORDER_ID, $trade_order_id );
		}
		$renewal->save();

		// 連線失敗、5xx、429：Shopline 可能已經扣款，結果會由交易通知（webhook）補上。
		// 判失敗的話 WCS 重試會換新的單號與冪等金鑰，等於再扣一次。
		$http = (int) ( $resp['http'] ?? 0 );
		if ( ! $resp['ok'] && 'NO_CREDENTIALS' !== $resp['code'] && ( 'TRANSPORT' === $resp['code'] || 0 === $http || $http >= 500 || 429 === $http ) ) {
			$renewal->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: merchant order number, 2: error message */
					__( 'The Shopline Payments renewal charge (order number %1$s) could not be confirmed: %2$s. Wait for the payment notice or check the Shopline Payments dashboard before charging again.', 'moksa-for-woocommerce' ),
					$reference_id,
					$resp['message']
				)
			);
			return;
		}

		if ( ! $resp['ok'] || 'FAILED' === $status ) {
			$renewal->update_status(
				'failed',
				sprintf(
					/* translators: %s: error message */
					__( 'The Shopline Payments renewal charge failed: %s', 'moksa-for-woocommerce' ),
					(string) ( $data['paymentMsg']['msg'] ?? $resp['message'] )
				)
			);
			return;
		}

		if ( 'SUCCEEDED' === $status ) {
			$renewal->payment_complete( $trade_order_id );
			$renewal->add_order_note(
				sprintf(
					/* translators: %s: Shopline Payments transaction ID */
					__( 'Shopline Payments renewal charge completed — transaction ID %s', 'moksa-for-woocommerce' ),
					$trade_order_id
				)
			);
			return;
		}

		// 處理中：結果由交易通知（webhook）補上，先別判失敗，否則重試會重複扣款。
		$renewal->update_status( 'on-hold', __( 'Shopline Payments is still processing the renewal charge. The order will be updated when the result arrives.', 'moksa-for-woocommerce' ) );
	}

	/**
	 * 回傳可扣款的付款工具 ID。
	 *
	 * 有記錄付款工具時只用那一張：失效就判失敗請顧客換卡，不自動改扣同一會員的其他卡
	 * （顧客沒同意用那張卡付這個訂閱）。只有首期通知沒帶付款工具 ID 時，才取該會員第一張可用的卡。
	 */
	private static function usable_instrument( string $customer_id, string $preferred ): string {
		$resp = Client::query_instruments( $customer_id );
		if ( ! $resp['ok'] ) {
			// 查詢失敗不擋扣款，照記錄的付款工具送，由交易結果決定成敗；沒有記錄就無從扣款。
			return $preferred;
		}
		$list   = (array) ( $resp['data']['paymentInstruments'] ?? [] );
		$usable = [];
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id      = (string) ( $item['instrumentId'] ?? '' );
			$status  = (string) ( $item['instrumentStatus'] ?? '' );
			$flag    = $item['instrumentCard']['expired'] ?? false;
			$expired = true === $flag || 'true' === $flag;
			if ( '' !== $id && 'SUCCESSED' === $status && ! $expired ) {
				$usable[] = $id;
			}
		}
		if ( '' !== $preferred ) {
			return in_array( $preferred, $usable, true ) ? $preferred : '';
		}
		return $usable[0] ?? '';
	}

	private static function server_ip(): string {
		$ip = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_ADDR'] ) ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '127.0.0.1';
	}

	public static function payment_meta( $meta, $subscription ) {
		$meta[ CardGateway::GATEWAY_ID ] = [
			'post_meta' => [
				Keys::SLP_CUSTOMER_ID   => [
					'value' => (string) $subscription->get_meta( Keys::SLP_CUSTOMER_ID ),
					'label' => __( 'Shopline Payments customer ID', 'moksa-for-woocommerce' ),
				],
				Keys::SLP_INSTRUMENT_ID => [
					'value' => (string) $subscription->get_meta( Keys::SLP_INSTRUMENT_ID ),
					'label' => __( 'Shopline Payments payment instrument ID', 'moksa-for-woocommerce' ),
				],
			],
		];
		return $meta;
	}

	/**
	 * @throws \Exception 沒填會員 ID 時擋下儲存.
	 */
	public static function validate_payment_meta( $meta, $subscription ): void {
		if ( '' === trim( (string) ( $meta['post_meta'][ Keys::SLP_CUSTOMER_ID ]['value'] ?? '' ) ) ) {
			throw new \Exception( esc_html__( 'Enter the Shopline Payments customer ID.', 'moksa-for-woocommerce' ) );
		}
	}

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
