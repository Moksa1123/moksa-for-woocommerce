<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments\Gateways;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Modules\ShoplinePayments\Api\Client;
use Moksafowo\Modules\ShoplinePayments\Api\Helper;
use Moksafowo\Order\Meta\Keys;

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
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			return false;
		}
		return Helper::has_credentials() && '' !== Helper::client_key();
	}

	public function payment_fields(): void {
		if ( '' !== $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC 結帳在上游已驗 nonce；這裡只讀 SDK 產生的一次性 paySession。
		$pay_session = isset( $_POST['moksafowo_slp_pay_session'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_slp_pay_session'] ) ) : '';
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
}
