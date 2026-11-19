<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments\Gateways;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Modules\ShoplinePayments\Api\Client;
use Moksafowo\Modules\ShoplinePayments\Api\Helper;
use Moksafowo\Order\Meta\Keys;
use Moksafowo\Http\CheckoutInput;

defined( 'ABSPATH' ) || exit;

/**
 * 內嵌式（SDK）信用卡：卡號在結帳頁的 SLP 欄位輸入，不跳轉。
 *
 * 導轉式收銀台只做一般付款；綁卡、定期扣款只有內嵌式支援，所以訂閱走這個付款方式。
 * 流程：SDK createPayment() 取得 paySession → 這裡建立交易拿回 nextAction →
 * 前端 payment.pay( nextAction )，3D 驗證後回到 returnUrl，結果以 webhook 為準。
 */
final class CardGateway extends \WC_Payment_Gateway {

	use TradePayload;

	public const GATEWAY_ID = 'moksafowo_shopline_card';

	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		$this->has_fields         = true;
		$this->method_title       = __( 'Shopline Payments credit card (on-page)', 'moksa-for-woocommerce' );
		$this->method_description = __( 'Customers enter their card on your checkout page without being redirected. Required for WooCommerce Subscriptions renewals.', 'moksa-for-woocommerce' );
		$this->supports           = [ 'products', 'refunds' ];
		if ( Subscriptions::active() ) {
			$this->supports = array_merge(
				$this->supports,
				[
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
				]
			);
		}

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title', __( 'Credit card', 'moksa-for-woocommerce' ) );
		$this->description = (string) $this->get_option( 'description', '' );
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
				'default'     => __( 'Credit card', 'moksa-for-woocommerce' ),
				'description' => __( 'The name customers see at checkout.', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'moksa-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'The payment method description shown at checkout.', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
		];
	}

	public function is_available(): bool {
		if ( ! parent::is_available() || ! \Moksafowo\Modules\Shared\Gateways\TwdOnly::ok() ) {
			return false;
		}
		// 「付款」頁是一般表單送出，拿不到建立交易後要交給 SDK 的 nextAction。
		// 例外：訂閱「變更付款方式」，送出後帶到訂閱頁的換卡表單再輸入卡號。
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			if ( ! $order instanceof \WC_Order || ! Subscriptions::is_changing_payment_method( $order ) ) {
				return false;
			}
		}
		return Helper::has_credentials() && '' !== Helper::client_key();
	}

	public function payment_fields(): void {
		if ( '' !== $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			echo '<p>' . esc_html__( 'You will enter the new card on the next page.', 'moksa-for-woocommerce' ) . '</p>';
			return;
		}
		?>
		<div class="moksafowo-slp-card" data-moksafowo-slp-gateway="<?php echo esc_attr( $this->id ); ?>">
			<div id="moksafowo-slp-card-container"></div>
			<input type="hidden" name="moksafowo_slp_pay_session" class="moksafowo-slp-pay-session" value="" />
			<p class="moksafowo-slp-error" role="alert" style="display:none;color:#b32d2e;"></p>
		</div>
		<?php
	}

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( esc_html__( 'The order could not be found', 'moksa-for-woocommerce' ) );
		}

		if ( Subscriptions::is_changing_payment_method( $order ) ) {
			// 顧客換卡：先不動訂閱（Subscriptions::delay_payment_method_change），帶到訂閱頁的換卡表單；
			// SLP 以 1 元驗卡、自動取消授權，webhook 確認綁卡成功才切換（WebhookHandler::handle_change）。
			if ( (int) $order->get_user_id() !== get_current_user_id() ) {
				wc_add_notice( __( 'This subscription could not be found.', 'moksa-for-woocommerce' ), 'error' );
				return [
					'result'   => 'failure',
					'redirect' => '',
				];
			}
			// 次數上限在真的送出卡號時才扣（start_change）；「同時更新所有訂閱」不支援（同綠界）。
			unset( $_POST['update_all_subscriptions_payment_method'] );
			$order->update_meta_data( Keys::SLP_CHANGE_UNTIL, (string) ( time() + self::CHANGE_TTL ) );
			$order->save();
			return [
				'result'   => 'success',
				'redirect' => add_query_arg( 'moksafowo_slp_card_change', '1', $order->get_view_order_url() ),
			];
		}

		$pay_session = CheckoutInput::post( 'moksafowo_slp_pay_session' );
		if ( '' === $pay_session ) {
			wc_add_notice( __( 'The card details were not received. Please enter the card again.', 'moksa-for-woocommerce' ), 'error' );
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}

		$for_subscription = Subscriptions::order_has_subscription( $order );
		$total            = (float) $order->get_total();
		if ( $for_subscription ) {
			// 首期 0 元（試用期）只綁卡：SLP 固定以 1 元驗卡後自動取消授權。
			$behavior = $total > 0 ? 'CardBindPayment' : 'CardBind';
		} else {
			$behavior = 'Regular';
		}

		$retry        = '' !== (string) $order->get_meta( Keys::SLP_REFERENCE_ID );
		$reference_id = Helper::build_reference_id( (int) $order_id, $retry );
		$confirm      = [
			'paymentMethod'   => 'CreditCard',
			'paymentBehavior' => $behavior,
		];
		if ( 'Regular' !== $behavior ) {
			$confirm['paymentInstrument'] = [ 'savePaymentInstrument' => true ];
		}

		$body = [
			'acquirerType'     => 'SDK',
			'referenceOrderId' => $reference_id,
			'amount'           => [
				'value'    => (int) round( max( $total, 'CardBind' === $behavior ? 1 : 0 ) * 100 ),
				'currency' => 'TWD',
			],
			'language'         => 'zh-TW',
			'returnUrl'        => $order->get_checkout_order_received_url(),
			'paySession'       => $pay_session,
			'confirm'          => $confirm,
			'order'            => $this->build_order( $order ),
			'billing'          => $this->build_billing( $order ),
			'customer'         => $this->build_customer( $order ),
			'client'           => $this->build_client(),
		];

		// 每次送出都記下單號：建立失敗後重試要換單號，否則同一把冪等金鑰可能一直拿到上次的錯誤。
		$order->update_meta_data( Keys::SLP_REFERENCE_ID, $reference_id );
		$order->save();
		$resp = Client::create_payment( $body, Helper::idempotent_key( 'pay-' . $reference_id . '-' . $pay_session ) );
		$data = (array) $resp['data'];
		Helper::log(
			'payment create',
			[
				'order_id'     => $order_id,
				'reference_id' => $reference_id,
				'behavior'     => $behavior,
				'ok'           => $resp['ok'],
				'code'         => $resp['code'],
				'status'       => (string) ( $data['status'] ?? '' ),
			]
		);

		$next_action = $data['nextAction'] ?? null;
		if ( ! $resp['ok'] || 'FAILED' === (string) ( $data['status'] ?? '' ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: error message */
					__( 'The Shopline Payments payment could not be created: %s', 'moksa-for-woocommerce' ),
					(string) ( $data['paymentMsg']['msg'] ?? $resp['message'] )
				),
				'error'
			);
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}

		$order->update_meta_data( Keys::SLP_REFERENCE_ID, $reference_id );
		$order->update_meta_data( Keys::SLP_TRADE_ORDER_ID, (string) ( $data['tradeOrderId'] ?? '' ) );
		$order->update_meta_data( Keys::SLP_STATUS, (string) ( $data['status'] ?? 'CREATED' ) );
		$order->update_meta_data( Keys::SLP_PAYMENT_METHOD, 'CreditCard' );
		$order->update_status( 'pending', __( 'Waiting for the customer to finish the Shopline Payments card payment.', 'moksa-for-woocommerce' ) );
		$order->save();

		// 官方回應範例寫 customer.CustomerId（大寫 C），欄位表寫 customerId；兩種都收。
		$customer_id = (string) ( $data['customer']['customerId'] ?? $data['customer']['CustomerId'] ?? $data['order']['customer']['customerId'] ?? '' );
		if ( $for_subscription && '' !== $customer_id ) {
			Subscriptions::store_payment_meta( $order, [ Keys::SLP_CUSTOMER_ID => $customer_id ] );
		}

		return [
			'result'                    => 'success',
			'redirect'                  => $order->get_checkout_order_received_url(),
			// 前端拿去 payment.pay()；官方要求原樣交給 SDK、不要解析。
			'moksafowo_slp_next_action' => null === $next_action ? '' : (string) wp_json_encode( $next_action ),
		];
	}

	/**
	 * 訂閱要綁卡，referenceCustomerId 不能用可預測的 WP 使用者編號（兩個站共用 Shopline 帳號時會對到同一個
	 * Shopline 會員）；改用每位會員專屬的隨機識別碼（最長 32 字，這裡 28 字）。
	 */
	protected function build_customer( \WC_Order $order ): array {
		$customer  = $this->build_customer_base( $order );
		$member_id = Subscriptions::member_id( $order );
		if ( '' !== $member_id ) {
			$customer['referenceCustomerId'] = $member_id;
		}
		return $customer;
	}

	/** 續扣（SubscriptionRenewal）建立交易時共用同一套訂單、帳單、顧客資料。 */
	public function trade_order( \WC_Order $order ): array {
		return $this->build_order( $order );
	}

	public function trade_billing( \WC_Order $order ): array {
		return $this->build_billing( $order );
	}

	public function trade_customer( \WC_Order $order ): array {
		return $this->build_customer( $order );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return $this->slp_refund( $order_id, $amount, (string) $reason );
	}

	/** 換卡頁有效期限：從「變更付款方式」送出起 30 分鐘。 */
	private const CHANGE_TTL = 1800;

	/** 這個訂閱有沒有進行中的顧客換卡。 */
	public static function change_pending( \WC_Order $subscription ): bool {
		return Subscriptions::is_changing_payment_method( $subscription )
			&& (int) $subscription->get_meta( Keys::SLP_CHANGE_UNTIL ) > time();
	}

	/** 訂閱本人、換卡期限內才回傳訂閱。 */
	private static function owned_pending_subscription( int $subscription_id ): ?\WC_Order {
		if ( ! Subscriptions::active() ) {
			return null;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription || (int) $subscription->get_user_id() !== get_current_user_id() || ! self::change_pending( $subscription ) ) {
			return null;
		}
		return $subscription;
	}

	/**
	 * 「我的帳號 → 訂閱 → 查看」帶 moksafowo_slp_card_change：顯示 SLP 卡號欄位與送出按鈕。
	 *
	 * @param mixed $subscription_id WCS 傳入的訂閱 ID。
	 */
	public static function render_change_form( $subscription_id ): void {
		if ( '1' !== \Moksafowo\Http\Input::get( 'moksafowo_slp_card_change' ) || '' === Helper::client_key() ) {
			return;
		}
		$subscription = self::owned_pending_subscription( absint( $subscription_id ) );
		if ( ! $subscription ) {
			return;
		}
		wp_enqueue_script( 'moksafowo-slp-sdk', Helper::SDK_URL, [], MOKSAFOWO_VERSION, true );
		$path = MOKSAFOWO_PLUGIN_DIR . 'assets/public/moksafowo-slp-card-change.js';
		wp_enqueue_script(
			'moksafowo-slp-card-change',
			MOKSAFOWO_PLUGIN_URL . 'assets/public/moksafowo-slp-card-change.js',
			[ 'jquery', 'moksafowo-slp-sdk' ],
			file_exists( $path ) ? MOKSAFOWO_VERSION . '.' . filemtime( $path ) : MOKSAFOWO_VERSION,
			true
		);
		wp_localize_script(
			'moksafowo-slp-card-change',
			'moksafowoSlpCardChange',
			[
				'clientKey'      => Helper::client_key(),
				'merchantId'     => Helper::merchant_id(),
				'env'            => Helper::sdk_env(),
				'startUrl'       => home_url( '/wc-api/moksafowo_slp_card_change' ),
				'subscriptionId' => $subscription->get_id(),
				'nonce'          => wp_create_nonce( 'moksafowo_slp_card_change_' . $subscription->get_id() ),
				'i18n'           => [
					'loadError' => __( 'The card form could not be loaded. Please refresh the page or choose another payment method.', 'moksa-for-woocommerce' ),
					'payError'  => __( 'The card could not be checked. Please check the card and try again.', 'moksa-for-woocommerce' ),
				],
			]
		);
		?>
		<div class="moksafowo-slp-card-change">
			<h3><?php esc_html_e( 'Change the card for this subscription', 'moksa-for-woocommerce' ); ?></h3>
			<p><?php esc_html_e( 'Enter the new card below. To check the card, Shopline Payments authorises NT$1 and cancels it automatically, so you are not charged. Your subscription keeps using the current card until the new one is saved.', 'moksa-for-woocommerce' ); ?></p>
			<div id="moksafowo-slp-card-change-container"></div>
			<p class="moksafowo-slp-error woocommerce-error" role="alert" style="display:none;"></p>
			<button type="button" class="button alt moksafowo-slp-card-change-submit"><?php esc_html_e( 'Save new card', 'moksa-for-woocommerce' ); ?></button>
		</div>
		<?php
	}

	/**
	 * 換卡表單送出（wc-api moksafowo_slp_card_change）：以 SDK 的 paySession 建立 1 元驗卡綁卡交易，
	 * 回傳 nextAction 給前端 payment.pay()。只限訂閱本人（nonce＋登入）、換卡期限內。
	 */
	public static function start_change(): void {
		$subscription_id = absint( \Moksafowo\Http\Input::post( 'subscription_id' ) );
		$nonce           = sanitize_text_field( \Moksafowo\Http\Input::post( 'nonce' ) );
		$pay_session     = sanitize_text_field( \Moksafowo\Http\Input::post( 'pay_session' ) );
		if ( ! $subscription_id || ! wp_verify_nonce( $nonce, 'moksafowo_slp_card_change_' . $subscription_id ) ) {
			wp_send_json_error( [ 'message' => __( 'This page has expired. Please start the card change again.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$subscription = self::owned_pending_subscription( $subscription_id );
		if ( ! $subscription ) {
			wp_send_json_error( [ 'message' => __( 'This page has expired. Please start the card change again.', 'moksa-for-woocommerce' ) ], 403 );
		}
		if ( '' === $pay_session ) {
			wp_send_json_error( [ 'message' => __( 'The card details were not received. Please enter the card again.', 'moksa-for-woocommerce' ) ] );
		}
		// 每送出一次卡號就會向發卡行驗卡：次數上限算在這裡。
		if ( ! Subscriptions::take_card_change_slot( get_current_user_id(), self::GATEWAY_ID ) ) {
			wp_send_json_error( [ 'message' => __( 'You have changed or tried to change your card too many times today. Please try again tomorrow or contact the store.', 'moksa-for-woocommerce' ) ], 429 );
		}

		$gateway      = new self();
		$reference_id = Helper::build_reference_id( $subscription->get_id(), true );
		$refs         = $subscription->get_meta( Keys::SLP_CHANGE_REFS );
		$refs         = is_array( $refs ) ? $refs : [];
		$refs[]       = $reference_id;
		$subscription->update_meta_data( Keys::SLP_CHANGE_REFS, array_slice( $refs, -20 ) );
		$subscription->save();

		$resp = Client::create_payment(
			[
				'acquirerType'     => 'SDK',
				'referenceOrderId' => $reference_id,
				'amount'           => [
					'value'    => 100,
					'currency' => 'TWD',
				],
				'language'         => 'zh-TW',
				'returnUrl'        => add_query_arg( 'moksafowo_slp_card_return', rawurlencode( $reference_id ), $subscription->get_view_order_url() ),
				'paySession'       => $pay_session,
				'confirm'          => [
					'paymentMethod'     => 'CreditCard',
					'paymentBehavior'   => 'CardBind',
					'paymentInstrument' => [ 'savePaymentInstrument' => true ],
				],
				'order'            => $gateway->build_order( $subscription ),
				'billing'          => $gateway->build_billing( $subscription ),
				'customer'         => $gateway->build_customer( $subscription ),
				'client'           => $gateway->build_client(),
			],
			Helper::idempotent_key( 'change-' . $reference_id . '-' . $pay_session )
		);
		$data = (array) $resp['data'];
		Helper::log(
			'card change create',
			[
				'subscription_id' => $subscription->get_id(),
				'reference_id'    => $reference_id,
				'ok'              => $resp['ok'],
				'code'            => $resp['code'],
				'status'          => (string) ( $data['status'] ?? '' ),
				'trade_order_id'  => (string) ( $data['tradeOrderId'] ?? '' ),
				'has_next_action' => isset( $data['nextAction'] ),
			]
		);
		if ( ! $resp['ok'] || 'FAILED' === (string) ( $data['status'] ?? '' ) ) {
			wp_send_json_error( [ 'message' => (string) ( $data['paymentMsg']['msg'] ?? $resp['message'] ) ] );
		}
		// 記下交易編號：webhook 沒到時，回到訂閱頁可以主動查（change_return_notice）。
		$trade_order_id = (string) ( $data['tradeOrderId'] ?? '' );
		if ( '' !== $trade_order_id ) {
			$fresh                   = wc_get_order( $subscription->get_id() );
			$trades                  = $fresh->get_meta( Keys::SLP_CHANGE_TRADES );
			$trades                  = is_array( $trades ) ? $trades : [];
			$trades[ $reference_id ] = $trade_order_id;
			$fresh->update_meta_data( Keys::SLP_CHANGE_TRADES, array_slice( $trades, -20, null, true ) );
			$fresh->save();
		}
		$next = $data['nextAction'] ?? null;
		wp_send_json_success(
			[
				'nextAction' => null === $next ? '' : (string) wp_json_encode( $next ),
				'redirect'   => add_query_arg( 'moksafowo_slp_card_return', rawurlencode( $reference_id ), $subscription->get_view_order_url() ),
			]
		);
	}

	/** 3D 驗證後回到訂閱頁（returnUrl）：依 webhook 是否已確認顯示訊息。 */
	public static function change_return_notice(): void {
		$ref = sanitize_text_field( \Moksafowo\Http\Input::get( 'moksafowo_slp_card_return' ) );
		if ( '' === $ref || ! function_exists( 'wc_add_notice' ) || ! Subscriptions::active() ) {
			return;
		}
		$subscription = wcs_get_subscription( (int) Helper::parse_order_id( $ref ) );
		if ( ! $subscription || (int) $subscription->get_user_id() !== get_current_user_id() ) {
			return;
		}
		$done = $subscription->get_meta( Keys::SLP_CHANGE_DONE );
		if ( ! is_array( $done ) || ! in_array( $ref, $done, true ) ) {
			// webhook 還沒到：主動向 SLP 查這筆交易的結果。
			$trades = $subscription->get_meta( Keys::SLP_CHANGE_TRADES );
			if ( is_array( $trades ) && '' !== (string) ( $trades[ $ref ] ?? '' ) ) {
				\Moksafowo\Modules\ShoplinePayments\Api\WebhookHandler::reconcile_change( $subscription, $ref, (string) $trades[ $ref ] );
				$subscription = wcs_get_subscription( $subscription->get_id() );
				$done         = $subscription ? $subscription->get_meta( Keys::SLP_CHANGE_DONE ) : [];
			}
		}
		if ( $subscription && hash_equals( (string) $subscription->get_meta( Keys::SLP_CHANGE_OK ), $ref ) ) {
			wc_add_notice( __( 'Your new card was saved. Your subscription will renew with it.', 'moksa-for-woocommerce' ) );
		} elseif ( is_array( $done ) && in_array( $ref, $done, true ) ) {
			wc_add_notice( __( 'The new card was not saved, so your subscription keeps using the current payment method. Please try again or contact the store.', 'moksa-for-woocommerce' ), 'error' );
		} else {
			wc_add_notice( __( 'Shopline Payments is still confirming the new card. Check this page again in a few minutes. Until then your subscription keeps using the current payment method.', 'moksa-for-woocommerce' ), 'notice' );
		}
		wp_safe_redirect( remove_query_arg( 'moksafowo_slp_card_return' ) );
		exit;
	}
}
