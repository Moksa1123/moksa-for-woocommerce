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
					'subscription_payment_method_change',
					'subscription_payment_method_change_customer',
					'subscription_payment_method_change_admin',
					'multiple_subscriptions',
				]
			);
		}
		return $supports;
	}

	/** 換卡頁有效期限：從「變更付款方式」送出起 30 分鐘。 */
	private const CHANGE_TTL = 1800;

	/** 顧客換卡的驗卡金額（同試用期綁卡，成功後放棄授權）。 */
	public static function verify_amount(): int {
		return max( 1, (int) get_option( 'moksafowo_ecpay_bind_amount', '1' ) );
	}

	/** 這個訂閱有沒有進行中的顧客換卡（從「變更付款方式」送出後 30 分鐘內）。 */
	public static function change_pending( \WC_Order $subscription ): bool {
		return Subscriptions::is_changing_payment_method( $subscription )
			&& (int) $subscription->get_meta( Keys::ECPAY_CHANGE_UNTIL ) > time();
	}

	protected function helper_has_credentials(): bool {
		return Helper::has_credentials();
	}

	protected function register_receipt_action(): void {
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'render_bind_form' ] );
	}

	/**
	 * 「我的帳號 → 訂閱 → 查看」帶 moksafowo_ecpay_card_change：顯示換卡表單。
	 * 只給這個訂閱的會員本人、且在換卡有效期限內。
	 *
	 * @param mixed $subscription_id WCS 傳入的訂閱 ID。
	 */
	public static function render_change_form( $subscription_id ): void {
		if ( '1' !== \Moksafowo\Http\Input::get( 'moksafowo_ecpay_card_change' ) || ! Subscriptions::active() ) {
			return;
		}
		$subscription = wcs_get_subscription( absint( $subscription_id ) );
		if ( ! $subscription || (int) $subscription->get_user_id() !== get_current_user_id() || ! self::change_pending( $subscription ) ) {
			return;
		}
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
		$gateway  = $gateways[ self::GATEWAY_ID ] ?? null;
		if ( $gateway instanceof self ) {
			$gateway->render_form( $subscription, true );
		}
	}


	/**
	 * 只在要綁卡的情境出現：購物車有訂閱、續訂購物車（顧客重付失敗的續訂單時 WCS 會把它放進結帳頁），
	 * 或在付款頁重付一張訂閱相關訂單。
	 */
	public function is_available(): bool {
		if ( ! Subscriptions::active() || ! parent::is_available() ) {
			return false;
		}
		// 「變更付款方式」表單送出時 WCS 在 wp_loaded 就查可用付款方式，那時還判斷不出 order-pay 頁。
		// 這個旗標網址就能設起來，但只影響「列不列出來」；真正換卡由 process_payment 檢查訂閱與本人。
		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) && ! empty( \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) ) {
			return true;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			// 「變更付款方式」頁的 order-pay 帶的是訂閱本身。
			return $order instanceof \WC_Order && ( Subscriptions::is_changing_payment_method( $order ) || Subscriptions::order_has_subscription( $order ) );
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
		if ( Subscriptions::is_changing_payment_method( $order ) ) {
			// 顧客換卡：先不動訂閱（Subscriptions::delay_payment_method_change），帶到換卡表單；
			// 驗卡成功、綁卡完成才切換（BindHandler::apply_change_result）。
			if ( (int) $order->get_user_id() !== get_current_user_id() ) {
				wc_add_notice( __( 'This subscription could not be found.', 'moksa-for-woocommerce' ), 'error' );
				return [
					'result'   => 'failure',
					'redirect' => '',
				];
			}
			// 次數上限在真的送出卡號時才扣（BindHandler::create），這裡只開一個 30 分鐘的換卡期限。
			// 「同時更新所有訂閱」要等綁卡成功才知道新卡，WCS 在這一步就會改其他訂閱，所以不支援。
			unset( $_POST['update_all_subscriptions_payment_method'] );
			$order->update_meta_data( Keys::ECPAY_CHANGE_UNTIL, (string) ( time() + self::CHANGE_TTL ) );
			$order->update_meta_data( Keys::ECPAY_CHANGE_FORMS, '0' );
			$order->save();
			return [
				'result'   => 'success',
				'redirect' => add_query_arg( 'moksafowo_ecpay_card_change', '1', $order->get_view_order_url() ),
			];
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
		$this->render_form( $order, false );
	}

	/**
	 * 綠界卡號表單（站內付 2.0 SDK）。
	 *
	 * @param \WC_Order $order  首期／重付的訂單，或換卡時的訂閱。
	 * @param bool      $change 是不是顧客換卡（只驗卡綁卡，成功後放棄授權）。
	 */
	public function render_form( \WC_Order $order, bool $change ): void {
		$merchant_trade_no = Helper::generate_merchant_trade_no( $order->get_id() );
		$member_id         = BindHandler::member_id( $order );
		$total             = $change ? 0 : (int) round( (float) $order->get_total() );
		if ( '' === $member_id ) {
			echo '<p class="woocommerce-error">' . esc_html__( 'Please log in to save a card for your subscription.', 'moksa-for-woocommerce' ) . '</p>';
			return;
		}
		if ( $change ) {
			// 每開一次表單就向綠界拿一組新的交易編號：同一個換卡期限內最多 5 次，避免重新整理一直產生。
			$forms = (int) $order->get_meta( Keys::ECPAY_CHANGE_FORMS );
			if ( $forms >= 5 ) {
				echo '<p class="woocommerce-error">' . esc_html__( 'This card change page has been opened too many times. Please start again from “Change payment”.', 'moksa-for-woocommerce' ) . '</p>';
				return;
			}
			$order->update_meta_data( Keys::ECPAY_CHANGE_FORMS, (string) ( $forms + 1 ) );
			$order->save();
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
					'TotalAmount'       => $total > 0 ? $total : self::verify_amount(),
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

		if ( $change ) {
			// 綁卡成功前不動訂閱的續扣憑證，換卡資料另外存。
			BindHandler::remember_change_mtn( $order, $merchant_trade_no );
			$order->update_meta_data( Keys::ECPAY_CHANGE_MEMBER, $member_id );
		} else {
			BindHandler::remember_mtn( $order, $merchant_trade_no );
			$order->update_meta_data( Keys::ECPAY_BIND_MEMBER_ID, $member_id );
		}
		$order->save();

		// SDK 必須在 #ECPayPayment 之後載入（35554），所以放 footer。
		wp_enqueue_script( 'moksafowo-ecpay-bind-sdk', Ecpg::sdk_url(), [ 'jquery' ], MOKSAFOWO_VERSION, true );
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
			<?php if ( $change ) : ?>
				<h3><?php esc_html_e( 'Change the card for this subscription', 'moksa-for-woocommerce' ); ?></h3>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: card check amount in NT$ */
							__( 'Enter the new card below. To check the card, ECPay authorises NT$%d and the authorisation is cancelled right away, so you are not charged. Your subscription keeps using the current card until the new one is saved.', 'moksa-for-woocommerce' ),
							self::verify_amount()
						)
					);
					?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'Enter your card below. It will be saved with ECPay so your subscription renews automatically.', 'moksa-for-woocommerce' ); ?></p>
			<?php endif; ?>
			<div id="ECPayPayment"></div>
			<p class="moksafowo-ecpay-bind-error woocommerce-error" role="alert" style="display:none;"></p>
			<button type="button" class="button alt moksafowo-ecpay-bind-submit"><?php echo $change ? esc_html__( 'Save new card', 'moksa-for-woocommerce' ) : esc_html__( 'Pay and save card', 'moksa-for-woocommerce' ); ?></button>
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

		foreach ( $steps as $i => $action ) {
			$result = Ecpg::do_action( $mtn, $trade_no, $action, $amount_int );
			if ( ! $result['ok'] && $i > 0 ) {
				// 已取消關帳（E）、放棄（N）失敗：把關帳送回去，避免這筆授權沒人請款。
				$back = Ecpg::do_action( $mtn, $trade_no, 'C', $amount_int );
				if ( $back['ok'] ) {
					$order->add_order_note( __( 'The capture was cancelled but the authorization could not be voided, so the capture was requested again. Nothing has changed.', 'moksa-for-woocommerce' ) );
				} else {
					$msg = __( 'The capture was cancelled but the authorization could not be voided or captured again. This payment is now not captured: handle it in the ECPay dashboard.', 'moksa-for-woocommerce' );
					$order->add_order_note( $msg );
					\Moksafowo\Refund\Guard::flag( $order, (string) $amount_int, $msg );
				}
			}
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
