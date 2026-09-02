<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Gateways;

use Moksafowo\Modules\Tappay\Api\Client;
use Moksafowo\Modules\Tappay\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * TapPay 電子錢包共用基底（LINE Pay / 街口 / 悠遊付 / 一卡通 / 全支付…）。
 *
 * 這幾家的 SDK 介面高度統一 —— 都是 `TPDirect.<wallet>.getPrime(cb)`，回傳
 * `{ status, msg, prime }`；後端一律走 pay-by-prime，回應帶 `payment_url`
 * 要把顧客導過去，完成後回 `frontend_redirect_url` + `backend_notify_url`。
 *
 * 那條導轉路徑跟信用卡 3DS 完全相同，所以 result / notify 兩個 webhook
 * 直接沿用，不必各家再寫一份。
 *
 * 子類只要宣告：gateway id、標題、以及前端 SDK 的命名空間（sdk_namespace）。
 */
abstract class AbstractWalletGateway extends \WC_Payment_Gateway {

	/** 前端 `TPDirect.<這個值>.getPrime()`，例如 linePay / jkoPay / easyWallet。 */
	abstract public function sdk_namespace(): string;

	abstract protected function default_title(): string;

	protected function default_description(): string {
		return '';
	}

	public function __construct() {
		$this->has_fields         = true; // 前端要跑 getPrime 才拿得到 token。
		$this->method_title       = $this->default_title();
		$this->method_description = $this->default_description();
		$this->supports           = [ 'products', 'refunds' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title', $this->default_title() );
		$this->description = (string) $this->get_option( 'description', $this->default_description() );
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
				'default'     => $this->default_title(),
				'description' => __( 'The name customers see at checkout.', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'moksa-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => $this->default_description(),
				'description' => __( 'The payment method description shown at checkout.', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
		];
	}

	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		return Helper::has_credentials();
	}

	public function payment_fields(): void {
		if ( '' !== $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
		// 錢包沒有要填的欄位 —— 只需要一個容器讓 JS 掛 getPrime，以及放 prime 的隱藏欄位。
		?>
		<div class="moksafowo-tappay-wallet"
			data-moksafowo-tappay-gateway="<?php echo esc_attr( $this->id ); ?>"
			data-moksafowo-tappay-sdk="<?php echo esc_attr( $this->sdk_namespace() ); ?>">
			<input type="hidden" name="moksafowo_tappay_prime" class="moksafowo-tappay-prime" value="" />
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
		// phpcs:enable

		if ( '' === $prime ) {
			wc_add_notice(
				__( 'The wallet did not return a payment token. Please try again.', 'moksa-for-woocommerce' ),
				'error'
			);
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}

		$retry        = '' !== (string) $order->get_meta( Keys::TAPPAY_ORDER_NUMBER );
		$order_number = Helper::build_order_number( $order, $retry );

		$result_url = add_query_arg(
			'order_number',
			rawurlencode( $order_number ),
			home_url( '/wc-api/moksafowo_tappay_result' )
		);
		$notify_url = home_url( '/wc-api/moksafowo_tappay_notify' );

		// 錢包類一定要帶 result_url 物件 —— 顧客會離站到錢包 App / 網頁完成付款。
		$payload = [
			'prime'        => $prime,
			'partner_key'  => Helper::partner_key(),
			'merchant_id'  => Helper::merchant_id(),
			'amount'       => (int) round( (float) $order->get_total() ),
			'currency'     => 'TWD',
			'order_number' => $order_number,
			'details'      => $this->build_details( $order ),
			'cardholder'   => [
				'phone_number' => (string) $order->get_billing_phone(),
				'name'         => trim( $order->get_formatted_billing_full_name() ),
				'email'        => (string) $order->get_billing_email(),
			],
			'result_url'   => [
				'frontend_redirect_url' => $result_url,
				'backend_notify_url'    => $notify_url,
			],
		];

		$resp = Client::pay_by_prime( $payload );

		Helper::log(
			'wallet pay-by-prime',
			[
				'gateway'      => $this->id,
				'order_id'     => $order_id,
				'order_number' => $order_number,
				'status'       => $resp['status'],
				'msg'          => $resp['msg'],
			]
		);

		$order->update_meta_data( Keys::TAPPAY_ORDER_NUMBER, $order_number );

		if ( ! $resp['ok'] ) {
			$order->update_meta_data( Keys::TAPPAY_TRANSACTION_STATUS, (string) $resp['status'] );
			$order->add_order_note(
				sprintf(
					/* translators: 1: wallet name, 2: status code, 3: message */
					__( '%1$s payment failed (status code %2$d): %3$s', 'moksa-for-woocommerce' ),
					$this->default_title(),
					(int) $resp['status'],
					(string) $resp['msg']
				)
			);
			$order->save();
			wc_add_notice(
				sprintf(
					/* translators: %s: message from the payment provider */
					__( 'The payment could not be started: %s', 'moksa-for-woocommerce' ),
					(string) $resp['msg']
				),
				'error'
			);
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}

		$rec_trade_id = (string) ( $resp['data']['rec_trade_id'] ?? '' );
		if ( '' !== $rec_trade_id ) {
			$order->update_meta_data( Keys::TAPPAY_REC_TRADE_ID, $rec_trade_id );
		}

		$payment_url = (string) ( $resp['payment_url'] ?? $resp['data']['payment_url'] ?? '' );
		if ( '' === $payment_url ) {
			// 錢包一定要導轉；沒拿到 URL 就是對方沒開通或參數不合，不能假裝成功。
			$order->add_order_note(
				sprintf(
					/* translators: %s: wallet name */
					__( '%s did not return a payment page to send the customer to.', 'moksa-for-woocommerce' ),
					$this->default_title()
				)
			);
			$order->save();
			wc_add_notice( __( 'The payment page could not be opened. Please choose another payment method.', 'moksa-for-woocommerce' ), 'error' );
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}

		$order->update_meta_data( Keys::TAPPAY_PAYMENT_URL, $payment_url );
		$order->update_status(
			'pending',
			sprintf(
				/* translators: %s: wallet name */
				__( 'Waiting for the customer to finish paying with %s.', 'moksa-for-woocommerce' ),
				$this->default_title()
			)
		);
		$order->save();

		return [
			'result'   => 'success',
			'redirect' => $payment_url,
		];
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'moksafowo_tappay_refund', __( 'The order could not be found', 'moksa-for-woocommerce' ) );
		}
		$rec_trade_id = (string) $order->get_meta( Keys::TAPPAY_REC_TRADE_ID );
		if ( '' === $rec_trade_id ) {
			return new \WP_Error( 'moksafowo_tappay_refund', __( 'This order has no TapPay transaction to refund.', 'moksa-for-woocommerce' ) );
		}

		$amt    = null === $amount ? (int) round( (float) $order->get_total() ) : (int) round( (float) $amount );
		$result = Client::refund( $rec_trade_id, $amt );
		if ( ! $result['ok'] ) {
			return new \WP_Error(
				'moksafowo_tappay_refund',
				sprintf(
					/* translators: 1: status code, 2: message */
					__( 'The TapPay refund failed (status code %1$d): %2$s', 'moksa-for-woocommerce' ),
					(int) $result['status'],
					(string) $result['msg']
				)
			);
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: reason */
				__( 'TapPay refunded %1$s. Reason: %2$s', 'moksa-for-woocommerce' ),
				wc_price( $amt ),
				'' !== $reason ? $reason : __( 'not given', 'moksa-for-woocommerce' )
			)
		);
		$order->save();
		return true;
	}

	/** 商品明細 —— TapPay 上限 100 字元，超過截斷。 */
	protected function build_details( \WC_Order $order ): string {
		$names = [];
		foreach ( $order->get_items() as $item ) {
			$names[] = $item->get_name();
		}
		$text = implode( ', ', $names );
		if ( '' === $text ) {
			/* translators: %s: order number */
			$text = sprintf( __( 'Order %s', 'moksa-for-woocommerce' ), $order->get_order_number() );
		}
		return mb_substr( $text, 0, 100 );
	}
}
