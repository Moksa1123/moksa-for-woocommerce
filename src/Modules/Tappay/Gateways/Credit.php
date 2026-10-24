<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Gateways;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Modules\Tappay\Api\Client;
use Moksafowo\Modules\Tappay\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Credit extends \WC_Payment_Gateway {

	public const GATEWAY_ID = 'moksafowo_tappay_credit';

	private const SUBSCRIPTION_FEATURES = [
		'subscriptions',
		'subscription_cancellation',
		'subscription_suspension',
		'subscription_reactivation',
		'subscription_amount_changes',
		'subscription_date_changes',
		'subscription_payment_method_change',
		'subscription_payment_method_change_customer',
		'subscription_payment_method_change_admin',
		'multiple_subscriptions',
	];

	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		$this->has_fields         = true; // 結帳頁要渲染 TapPay Fields。
		$this->method_title       = __( 'TapPay credit card', 'moksa-for-woocommerce' );
		$this->method_description = __( 'Secure card payments with 3-D Secure. Card details never pass through this site.', 'moksa-for-woocommerce' );
		$this->supports           = [ 'products', 'refunds' ];
		if ( Subscriptions::active() ) {
			$this->supports = array_merge( $this->supports, self::SUBSCRIPTION_FEATURES );
			// 顧客換卡走 Bind Card（不經 3D 驗證）；商家要求 3D 驗證時不開放，只能後台換卡。
			if ( Helper::three_domain_secure_enabled() ) {
				$this->supports = array_values( array_diff( $this->supports, [ 'subscription_payment_method_change', 'subscription_payment_method_change_customer' ] ) );
			}
		}

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title', __( 'TapPay credit card', 'moksa-for-woocommerce' ) );
		$this->description = (string) $this->get_option( 'description', __( 'Pay securely by card, handled by TapPay.', 'moksa-for-woocommerce' ) );
		$this->enabled     = (string) $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'     => [
				'title'   => __( 'Enable this payment method', 'moksa-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'TapPay credit card', 'moksa-for-woocommerce' ),
				'description' => __( 'The name customers see at checkout.', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'moksa-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => __( 'Pay securely by card, handled by TapPay.', 'moksa-for-woocommerce' ),
				'description' => __( 'The payment method description shown at checkout.', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
		];
	}

	public function is_available(): bool {
		if ( ! parent::is_available() || ! \Moksafowo\Modules\Shared\Gateways\TwdOnly::ok() ) {
			return false;
		}
		// 首期 0 元只能用 Bind Card 綁卡，那條路沒有 3D 驗證；商家要求 3D 時不提供，免得變成免驗證試卡的入口。
		if ( Helper::three_domain_secure_enabled() && WC()->cart && 0 >= $this->get_order_total() && class_exists( 'WC_Subscriptions_Cart' ) && \WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return false;
		}
		return Helper::has_credentials();
	}

	public function payment_fields(): void {
		if ( '' !== $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
		?>
		<div class="moksafowo-tappay-fields" data-moksafowo-tappay-gateway="<?php echo esc_attr( $this->id ); ?>">
			<p class="form-row form-row-wide">
				<label for="moksafowo-tappay-card-number"><?php esc_html_e( 'Card number', 'moksa-for-woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
				<span id="moksafowo-tappay-card-number" class="moksafowo-tappay-field input-text"></span>
			</p>
			<p class="form-row form-row-first">
				<label for="moksafowo-tappay-card-expiry"><?php esc_html_e( 'Expiry (MM / YY)', 'moksa-for-woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
				<span id="moksafowo-tappay-card-expiry" class="moksafowo-tappay-field input-text"></span>
			</p>
			<p class="form-row form-row-last">
				<label for="moksafowo-tappay-card-ccv"><?php esc_html_e( 'CVC', 'moksa-for-woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
				<span id="moksafowo-tappay-card-ccv" class="moksafowo-tappay-field input-text"></span>
			</p>
			<input type="hidden" name="moksafowo_tappay_prime" class="moksafowo-tappay-prime" value="" />
			<input type="hidden" name="moksafowo_tappay_bin" class="moksafowo-tappay-bin" value="" />
			<input type="hidden" name="moksafowo_tappay_last_four" class="moksafowo-tappay-last-four" value="" />
			<input type="hidden" name="moksafowo_tappay_issuer" class="moksafowo-tappay-issuer" value="" />
			<p class="moksafowo-tappay-error" role="alert" style="display:none;color:#b32d2e;"></p>
		</div>
		<?php
	}


	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( esc_html__( 'The order could not be found', 'moksa-for-woocommerce' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC checkout 在上游已驗 nonce；此處僅讀前端取得的一次性 prime
		$prime = isset( $_POST['moksafowo_tappay_prime'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_tappay_prime'] ) ) : '';
		$bin   = isset( $_POST['moksafowo_tappay_bin'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_tappay_bin'] ) ) : '';
		$last4 = isset( $_POST['moksafowo_tappay_last_four'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_tappay_last_four'] ) ) : '';
		$bank  = isset( $_POST['moksafowo_tappay_issuer'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_tappay_issuer'] ) ) : '';
		// phpcs:enable

		if ( '' === $prime ) {
			wc_add_notice( __( 'TapPay did not return a payment token. Please enter the card details again.', 'moksa-for-woocommerce' ), 'error' );
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}

		$for_subscription = Subscriptions::order_has_subscription( $order );
		// 變更付款方式、或首期 0 元（試用期）：只綁卡，不扣款。
		if ( $for_subscription && ( Subscriptions::is_changing_payment_method( $order ) || (float) $order->get_total() <= 0 ) ) {
			if ( Helper::three_domain_secure_enabled() ) {
				wc_add_notice( __( 'This store requires 3-D Secure, so a card cannot be saved without a payment. Please choose another payment method.', 'moksa-for-woocommerce' ), 'error' );
				return [
					'result'   => 'failure',
					'redirect' => '',
				];
			}
			return $this->bind_card_only( $order, $prime, $last4 );
		}

		// 重試（曾送過 order_number）時加 T{time} 後綴避免 TapPay 拒重複。
		$retry        = '' !== (string) $order->get_meta( Keys::TAPPAY_ORDER_NUMBER );
		$order_number = Helper::build_order_number( $order, $retry );

		$use_3ds    = Helper::three_domain_secure_enabled();
		$result_url = add_query_arg(
			[
				'order_number' => rawurlencode( $order_number ),
				'k'            => Helper::result_token( $order ),
			],
			home_url( '/wc-api/moksafowo_tappay_result' )
		);
		$notify_url = home_url( '/wc-api/moksafowo_tappay_notify' );

		$payload = [
			'prime'               => $prime,
			'partner_key'         => Helper::partner_key(),
			'merchant_id'         => Helper::merchant_id(),
			'amount'              => (int) round( (float) $order->get_total() ), // TWD = 整數元，不 ×100。
			'currency'            => 'TWD',
			'order_number'        => $order_number,
			'details'             => self::build_details( $order ),
			'cardholder'          => [
				'phone_number' => (string) $order->get_billing_phone(),
				'name'         => trim( $order->get_formatted_billing_full_name() ),
				'email'        => (string) $order->get_billing_email(),
			],
			'three_domain_secure' => $use_3ds,
			// TapPay 正式 API：result_url 為物件（3DS 完成後同步導回 +
			// 後端 notify）。另帶 top-level notify_url 以相容 spec 簡化寫法。
			'result_url'          => [
				'frontend_redirect_url' => $result_url,
				'backend_notify_url'    => $notify_url,
			],
			'notify_url'          => $notify_url,
		];
		if ( $for_subscription ) {
			$payload['remember'] = true;
		}

		$resp = Client::pay_by_prime( $payload );

		Helper::log(
			'pay-by-prime',
			[
				'order_id'     => $order_id,
				'order_number' => $order_number,
				'status'       => $resp['status'],
				'msg'          => $resp['msg'],
				'needs_3ds'    => $resp['needs_3ds'],
			]
		);

		$order->update_meta_data( Keys::TAPPAY_ORDER_NUMBER, $order_number );
		$order->update_meta_data( Keys::TAPPAY_THREE_DOMAIN_SECURE, $use_3ds ? 'yes' : 'no' );
		if ( '' !== $bin ) {
			$order->update_meta_data( Keys::TAPPAY_CARD_BIN, $bin );
		}
		if ( '' !== $last4 ) {
			$order->update_meta_data( Keys::TAPPAY_CARD_LAST4, $last4 );
		}
		if ( '' !== $bank ) {
			$order->update_meta_data( Keys::TAPPAY_CARD_ISSUER, $bank );
		}
		$rec_trade_id = (string) ( $resp['data']['rec_trade_id'] ?? '' );
		if ( '' !== $rec_trade_id ) {
			$order->update_meta_data( Keys::TAPPAY_REC_TRADE_ID, $rec_trade_id );
		}
		// 記住的卡片在 3D 驗證之前就回傳了。先只存在這張訂單上，付款確認後才寫進訂閱
		// （IpnHandler 付款成功時呼叫 SubscriptionRenewal::promote_pending_card）；
		// 否則續訂單重付、換方案時 3D 沒過，訂閱的卡就已經被換成沒驗證過的卡。
		if ( $for_subscription && $resp['ok'] ) {
			$secret = (array) ( $resp['data']['card_secret'] ?? [] );
			if ( $resp['needs_3ds'] ) {
				$order->update_meta_data( Keys::TAPPAY_PENDING_CARD_KEY, (string) ( $secret['card_key'] ?? '' ) );
				$order->update_meta_data( Keys::TAPPAY_PENDING_CARD_TOKEN, (string) ( $secret['card_token'] ?? '' ) );
			} else {
				$this->store_card_secret( $order, $secret, $last4 );
			}
		}

		if ( ! $resp['ok'] ) {
			$order->update_meta_data( Keys::TAPPAY_TRANSACTION_STATUS, (string) $resp['status'] );
			$order->save();
			wc_add_notice(
				sprintf(
					/* translators: 1: status code, 2: message */
					__( 'The TapPay payment failed (status code %1$d): %2$s', 'moksa-for-woocommerce' ),
					$resp['status'],
					$resp['msg']
				),
				'error'
			);
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}

		// 3DS challenge — 導去 TapPay payment_url，完成後回 result_url。
		if ( $resp['needs_3ds'] ) {
			$order->update_meta_data( Keys::TAPPAY_PAYMENT_URL, $resp['payment_url'] );
			\Moksafowo\Modules\Tappay\Api\IpnHandler::schedule_pending_check( $order );
			$order->update_status( 'pending', __( 'Waiting for the customer to finish the TapPay 3-D Secure check.', 'moksa-for-woocommerce' ) );
			$order->save();
			return [
				'result'   => 'success',
				'redirect' => $resp['payment_url'],
			];
		}

		// Frictionless / 無 3DS — 已成交。
		$bank_txn  = (string) ( $resp['data']['bank_transaction_id'] ?? '' );
		$auth_code = (string) ( $resp['data']['auth_code'] ?? '' );
		if ( '' !== $bank_txn ) {
			$order->update_meta_data( Keys::TAPPAY_BANK_TRANSACTION_ID, $bank_txn );
		}
		if ( '' !== $auth_code ) {
			$order->update_meta_data( Keys::TAPPAY_AUTH_CODE, $auth_code );
		}
		$order->update_meta_data( Keys::TAPPAY_TRANSACTION_STATUS, '0' );
		$order->payment_complete( $rec_trade_id );
		$order->add_order_note(
			sprintf(
				/* translators: 1: rec_trade_id, 2: card last4 */
				__( 'TapPay card payment completed — transaction ID %1$s (card ending %2$s)', 'moksa-for-woocommerce' ),
				$rec_trade_id,
				$last4
			)
		);
		$order->save();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		];
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'moksafowo_tappay_invalid_order', __( 'The order does not exist.', 'moksa-for-woocommerce' ) );
		}
		$rec_trade_id = (string) $order->get_meta( Keys::TAPPAY_REC_TRADE_ID );
		if ( '' === $rec_trade_id ) {
			return new \WP_Error( 'moksafowo_tappay_missing_rec_trade_id', __( 'The order has no TapPay transaction ID.', 'moksa-for-woocommerce' ) );
		}

		$amt = (int) round( (float) $amount );
		if ( $amt <= 0 ) {
			return new \WP_Error( 'moksafowo_tappay_invalid_amount', __( 'The refund amount must be greater than 0.', 'moksa-for-woocommerce' ) );
		}

		$result = Client::refund( $rec_trade_id, $amt );
		Helper::log(
			'refund',
			[
				'order_id'     => $order_id,
				'rec_trade_id' => $rec_trade_id,
				'amount'       => $amt,
				'status'       => $result['status'],
				'msg'          => $result['msg'],
			]
		);

		if ( ! $result['ok'] && 10024 === (int) $result['status'] && $amt < (int) round( (float) $order->get_total() ) ) {
			return new \WP_Error(
				'moksafowo_tappay_refund_fail',
				__( 'This payment has not been captured yet, so TapPay can only refund the full amount. Refund the full amount, or wait until the payment is captured.', 'moksa-for-woocommerce' )
			);
		}
		if ( ! $result['ok'] ) {
			return new \WP_Error(
				'moksafowo_tappay_refund_fail',
				sprintf(
					/* translators: 1: status code, 2: message */
					__( 'The TapPay refund failed (status code %1$d): %2$s', 'moksa-for-woocommerce' ),
					$result['status'],
					$result['msg']
				)
			);
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: rec_trade_id, 3: reason */
				__( 'The TapPay refund of NT$%1$s was submitted (transaction ID %2$s) — %3$s', 'moksa-for-woocommerce' ),
				$amt,
				$rec_trade_id,
				'' !== $reason ? $reason : __( 'No reason given', 'moksa-for-woocommerce' )
			)
		);
		$order->save();
		return true;
	}

	private function bind_card_only( \WC_Order $order, string $prime, string $last4 ): array {
		$resp = Client::bind_card(
			[
				'prime'               => $prime,
				'partner_key'         => Helper::partner_key(),
				'merchant_id'         => Helper::merchant_id(),
				'currency'            => 'TWD',
				'details'             => self::build_details( $order ),
				'three_domain_secure' => false,
				'cardholder'          => [
					'phone_number' => (string) $order->get_billing_phone(),
					'name'         => trim( $order->get_formatted_billing_full_name() ),
					'email'        => (string) $order->get_billing_email(),
				],
			]
		);
		Helper::log(
			'bind-card',
			[
				'order_id' => $order->get_id(),
				'status'   => $resp['status'],
				'msg'      => $resp['msg'],
			]
		);

		$secret = (array) ( $resp['data']['card_secret'] ?? [] );
		if ( ! $resp['ok'] || empty( $secret['card_key'] ) || empty( $secret['card_token'] ) ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: status code, 2: message */
					__( 'The card could not be saved with TapPay (status code %1$d): %2$s', 'moksa-for-woocommerce' ),
					$resp['status'],
					$resp['msg']
				),
				'error'
			);
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}

		$last4 = (string) ( $resp['data']['card_info']['last_four'] ?? $last4 );
		$this->store_card_secret( $order, $secret, $last4 );
		$order->add_order_note(
			sprintf(
				/* translators: %s: card last four digits */
				__( 'Card ending %s saved with TapPay for subscription renewals.', 'moksa-for-woocommerce' ),
				$last4
			)
		);

		if ( Subscriptions::is_changing_payment_method( $order ) ) {
			return [
				'result'   => 'success',
				'redirect' => $order->get_view_order_url(),
			];
		}

		$order->payment_complete();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		];
	}

	private function store_card_secret( \WC_Order $order, array $secret, string $last4 ): void {
		$key   = (string) ( $secret['card_key'] ?? '' );
		$token = (string) ( $secret['card_token'] ?? '' );
		if ( '' === $key || '' === $token ) {
			return;
		}
		$meta = [
			Keys::TAPPAY_CARD_KEY   => $key,
			Keys::TAPPAY_CARD_TOKEN => $token,
		];
		if ( '' !== $last4 ) {
			$meta[ Keys::TAPPAY_CARD_LAST4 ] = $last4;
		}
		Subscriptions::store_payment_meta( $order, $meta );
	}

	public static function build_details( \WC_Order $order ): string {
		$names = [];
		foreach ( $order->get_items() as $item ) {
			$names[] = (string) $item->get_name();
		}
		$detail = '' !== implode( ',', $names )
			? implode( ',', $names )
			/* translators: %s: site name */
			: sprintf( __( '%s order', 'moksa-for-woocommerce' ), get_bloginfo( 'name' ) );
		return mb_substr( $detail, 0, 100 );
	}
}
