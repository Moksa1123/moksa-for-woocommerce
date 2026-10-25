<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Modules\Ecpay\Api\BindHandler;
use Moksafowo\Modules\Ecpay\Api\Ecpg;
use Moksafowo\Modules\Ecpay\Api\Helper;
use Moksafowo\Modules\Shared\Gateways\AbstractMowcGateway;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 站內付 2.0 綁定信用卡：給 WooCommerce Subscriptions 自動續扣用。
 *
 * 首期走官方「首次交易與信用卡綁定」（35536 應用場景二）：顧客在付款頁輸入卡號，
 * 這筆授權就是首期款，同時取得 BindCardID；之後由 SubscriptionRenewal 以
 * CreatePaymentWithCardID 幕後扣款。首期 0 元（試用期）則以 1 元授權綁卡後放棄授權（場景一）。
 */
final class CreditBind extends AbstractMowcGateway {

	public const GATEWAY_ID = 'moksafowo_ecpay_credit_bind';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function build_method_title(): string {
		return __( 'ECPay credit card (saved for subscriptions)', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Customers enter their card on an ECPay card form on your site, and the card is saved so WooCommerce Subscriptions renewals are charged automatically. ECPay must enable card binding for your store first, and daily automatic capture must be turned on in the ECPay dashboard, otherwise the payments are never captured.', 'moksa-for-woocommerce' );
	}

	protected function gateway_supports(): array {
		$supports = [ 'products', 'refunds' ];
		if ( Subscriptions::active() ) {
			$supports = array_merge(
				$supports,
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
		return $supports;
	}

	protected function helper_has_credentials(): bool {
		return Helper::has_credentials();
	}

	protected function register_receipt_action(): void {
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'render_bind_form' ] );
	}


	/**
	 * 只在要綁卡的情境出現：購物車有訂閱、續訂購物車（顧客重付失敗的續訂單時 WCS 會把它放進結帳頁），
	 * 或在付款頁重付一張訂閱相關訂單。
	 */
	public function is_available(): bool {
		if ( ! Subscriptions::active() || ! parent::is_available() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			return $order instanceof \WC_Order && Subscriptions::order_has_subscription( $order );
		}
		if ( function_exists( 'wcs_cart_contains_renewal' ) && wcs_cart_contains_renewal() ) {
			return true;
		}
		return class_exists( 'WC_Subscriptions_Cart' ) && \WC_Subscriptions_Cart::cart_contains_subscription();
	}

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( esc_html__( 'Invalid order', 'moksa-for-woocommerce' ) );
		}
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		];
	}

	public function render_bind_form( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order->is_paid() ) {
			return;
		}

		$merchant_trade_no = Helper::generate_merchant_trade_no( $order->get_id() );
		$member_id         = BindHandler::member_id( $order );
		$total             = (int) round( (float) $order->get_total() );
		if ( '' === $member_id ) {
			echo '<p class="woocommerce-error">' . esc_html__( 'Please log in to save a card for your subscription.', 'moksa-for-woocommerce' ) . '</p>';
			return;
		}

		$resp = Ecpg::get_binding_token(
			[
				'PlatformID'     => '',
				'MerchantID'     => Helper::merchant_id(),
				'ConsumerInfo'   => BindHandler::consumer_info( $order, $member_id ),
				'OrderInfo'      => [
					'MerchantTradeDate' => current_time( 'Y/m/d H:i:s' ),
					'MerchantTradeNo'   => $merchant_trade_no,
					// 這筆授權就是首期款；試用期 0 元時以「驗卡金額」授權綁卡，成功後放棄授權（BindHandler）。
					'TotalAmount'       => $total > 0 ? $total : max( 1, (int) get_option( 'moksafowo_ecpay_bind_amount', '1' ) ),
					'TradeDesc'         => BindHandler::item_name( $order ),
					'ItemName'          => BindHandler::item_name( $order ),
					'ReturnURL'         => home_url( '/wc-api/moksafowo_ecpay_bind_notify' ),
				],
				'OrderResultURL' => add_query_arg(
					[
						'order_id' => $order->get_id(),
						'key'      => $order->get_order_key(),
					],
					home_url( '/wc-api/moksafowo_ecpay_bind_result' )
				),
			]
		);

		if ( ! $resp['ok'] || empty( $resp['data']['Token'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'The ECPay card form could not be opened: %s', 'moksa-for-woocommerce' ),
					$resp['message']
				)
			);
			echo '<p class="woocommerce-error">' . esc_html__( 'The card form could not be loaded. Please refresh the page or choose another payment method.', 'moksa-for-woocommerce' ) . '</p>';
			return;
		}

		BindHandler::remember_mtn( $order, $merchant_trade_no );
		$order->update_meta_data( Keys::ECPAY_BIND_MEMBER_ID, $member_id );
		$order->save();

		// SDK 必須在 #ECPayPayment 之後載入（35554），所以放 footer。
		wp_enqueue_script( 'moksafowo-ecpay-bind-sdk', Ecpg::sdk_url(), [ 'jquery' ], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- 綠界官方 SDK，版本在路徑上。
		// 綠界 SDK 直接呼叫全域 $.ajax，WordPress 的 jQuery 是 noConflict 模式沒有 $；只在這個付款頁補上。
		wp_add_inline_script( 'moksafowo-ecpay-bind-sdk', 'window.$ = window.$ || window.jQuery;', 'before' );
		$path = MOKSAFOWO_PLUGIN_DIR . 'assets/public/moksafowo-ecpay-bind.js';
		wp_enqueue_script(
			'moksafowo-ecpay-bind',
			MOKSAFOWO_PLUGIN_URL . 'assets/public/moksafowo-ecpay-bind.js',
			[ 'jquery', 'moksafowo-ecpay-bind-sdk' ],
			file_exists( $path ) ? MOKSAFOWO_VERSION . '.' . filemtime( $path ) : MOKSAFOWO_VERSION,
			true
		);
		wp_localize_script(
			'moksafowo-ecpay-bind',
			'moksafowoEcpayBind',
			[
				'token'      => (string) $resp['data']['Token'],
				'serverType' => Ecpg::sdk_server_type(),
				'createUrl'  => home_url( '/wc-api/moksafowo_ecpay_bind_create' ),
				'orderId'    => $order->get_id(),
				'orderKey'   => $order->get_order_key(),
				'i18n'       => [
					'loadError' => __( 'The card form could not be loaded. Please refresh the page or choose another payment method.', 'moksa-for-woocommerce' ),
					'payError'  => __( 'The card payment did not go through. Please check the card and try again.', 'moksa-for-woocommerce' ),
				],
			]
		);
		?>
		<div class="moksafowo-ecpay-bind">
			<p><?php esc_html_e( 'Enter your card below. It will be saved with ECPay so your subscription renews automatically.', 'moksa-for-woocommerce' ); ?></p>
			<div id="ECPayPayment"></div>
			<p class="moksafowo-ecpay-bind-error woocommerce-error" role="alert" style="display:none;"></p>
			<button type="button" class="button alt moksafowo-ecpay-bind-submit"><?php esc_html_e( 'Pay and save card', 'moksa-for-woocommerce' ); ?></button>
		</div>
		<?php
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'moksafowo_ecpay_refund_invalid_order', __( 'The order could not be found.', 'moksa-for-woocommerce' ) );
		}
		$amount_int = (int) round( (float) $amount );
		if ( $amount_int <= 0 ) {
			return new \WP_Error( 'moksafowo_ecpay_refund_amount', __( 'The refund amount must be greater than 0.', 'moksa-for-woocommerce' ) );
		}
		$mtn      = (string) $order->get_meta( Keys::ECPAY_MERCHANT_TRADE_NO );
		$trade_no = (string) $order->get_meta( Keys::ECPAY_TRADE_NO );
		if ( '' === $mtn || '' === $trade_no ) {
			return new \WP_Error( 'moksafowo_ecpay_credit_action_missing_meta', __( 'No ECPay transaction ID was found, so the refund cannot be made here. Please refund it in the ECPay dashboard.', 'moksa-for-woocommerce' ) );
		}

		// 依請退款流程（35637）：已授權只能整筆放棄；要關帳整筆要先取消再放棄、部分用退刷；已關帳用退刷。
		$query   = Helper::query_credit_trade( $order );
		$status  = is_wp_error( $query ) ? '' : (string) ( $query['RtnValue']['Status'] ?? '' );
		$is_full = $amount_int >= (int) round( (float) $order->get_total() );
		if ( 'Authorized' === $status ) {
			if ( ! $is_full ) {
				return new \WP_Error( 'moksafowo_ecpay_refund_before_capture', __( 'This ECPay card payment has not been captured yet, so only the full amount can be refunded now (by cancelling the authorization). For a partial refund, try again after capture completes, usually the next day.', 'moksa-for-woocommerce' ) );
			}
			$steps = [ 'N' ];
		} elseif ( 'To be captured' === $status && $is_full ) {
			$steps = [ 'E', 'N' ];
		} else {
			$steps = [ 'R' ];
		}

		foreach ( $steps as $action ) {
			$result = Ecpg::do_action( $mtn, $trade_no, $action, $amount_int );
			if ( ! $result['ok'] ) {
				$msg = sprintf(
					/* translators: 1: amount, 2: ECPay message, 3: code */
					__( 'The ECPay refund of NT$%1$d failed: %2$s (code %3$d)', 'moksa-for-woocommerce' ),
					$amount_int,
					$result['message'],
					(int) ( $result['data']['RtnCode'] ?? 0 )
				);
				$order->add_order_note( $msg );
				return new \WP_Error( 'moksafowo_ecpay_refund_failed', $msg );
			}
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: reason */
				__( 'ECPay refunded NT$%1$d. Reason: %2$s', 'moksa-for-woocommerce' ),
				$amount_int,
				'' === $reason ? __( '(not given)', 'moksa-for-woocommerce' ) : $reason
			)
		);
		return true;
	}
}
