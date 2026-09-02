<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Gateways;

use Moksafowo\Modules\Tappay\Api\Client;
use Moksafowo\Modules\Tappay\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * TapPay 行動支付共用基底（Apple Pay / Google Pay / Samsung Pay）。
 *
 * 跟電子錢包（AbstractWalletGateway）差在三點：
 *   1. **不導轉** —— 拿到 prime 打 pay-by-prime 就直接成交，跟信用卡一樣，
 *      沒有 payment_url。（3DS 仍可能觸發，走跟信用卡相同的分支。）
 *   2. **要做可用性偵測** —— 裝置 / 瀏覽器不支援時這個付款方式不該出現在結帳頁，
 *      否則顧客選了才發現按不下去。前端偵測結果回寫到 session，
 *      is_available() 據此隱藏。
 *   3. **各有前置作業** —— Apple 要網域驗證、Google 要 Merchant ID，
 *      設定沒填完就不顯示，避免顧客撞到必然失敗的付款方式。
 *
 * 子類宣告：gateway id、標題、SDK 命名空間、以及必要設定欄位。
 */
abstract class AbstractDeviceWalletGateway extends \WC_Payment_Gateway {

	/** 前端 `TPDirect.<這個值>`，例如 paymentRequestApi / googlePay / samsungPay。 */
	abstract public function sdk_namespace(): string;

	abstract protected function default_title(): string;

	/** 這個付款方式專屬的設定欄位（Apple 的 merchant id、Google 的 merchant id…）。 */
	protected function extra_form_fields(): array {
		return [];
	}

	/**
	 * 前置設定是否齊備。缺的話結帳頁不顯示 —— 顯示了顧客也只會失敗。
	 * 子類有必填設定時覆寫。
	 */
	protected function prerequisites_met(): bool {
		return true;
	}

	protected function default_description(): string {
		return '';
	}

	public function __construct() {
		$this->has_fields         = true;
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
		$this->form_fields = array_merge(
			[
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
			],
			$this->extra_form_fields()
		);
	}

	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( ! Helper::has_credentials() || ! $this->prerequisites_met() ) {
			return false;
		}
		// 前端偵測過且回報不支援 → 隱藏。還沒偵測過（null）時先顯示，
		// 由前端偵測後透過 update_order_review 重新詢問，避免首次載入時誤藏。
		$supported = $this->device_support_flag();
		return false !== $supported;
	}

	/**
	 * 前端把偵測結果寫進 WC session；這裡讀回來。
	 * 回傳 true / false / null（尚未偵測）。
	 */
	protected function device_support_flag(): ?bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$flags = WC()->session->get( 'moksafowo_tappay_device_support' );
		if ( ! is_array( $flags ) || ! array_key_exists( $this->id, $flags ) ) {
			return null;
		}
		return (bool) $flags[ $this->id ];
	}

	public function payment_fields(): void {
		if ( '' !== $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
		?>
		<div class="moksafowo-tappay-devicewallet"
			data-moksafowo-tappay-gateway="<?php echo esc_attr( $this->id ); ?>"
			data-moksafowo-tappay-sdk="<?php echo esc_attr( $this->sdk_namespace() ); ?>">
			<div class="moksafowo-tappay-devicewallet-button"></div>
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
				sprintf(
					/* translators: %s: payment method name */
					__( '%s did not return a payment token. Please try again.', 'moksa-for-woocommerce' ),
					$this->default_title()
				),
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
			// 行動支付的 token 已是 3DS 等效驗證，但帶著 result_url 才能
			// 涵蓋發卡行仍要求 challenge 的少數情形。
			'result_url'   => [
				'frontend_redirect_url' => $result_url,
				'backend_notify_url'    => $notify_url,
			],
		];

		$resp = Client::pay_by_prime( $payload );

		Helper::log(
			'device-wallet pay-by-prime',
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
					/* translators: 1: payment method name, 2: status code, 3: message */
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
					__( 'The payment could not be completed: %s', 'moksa-for-woocommerce' ),
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

		// 少數情形發卡行仍要求 3DS challenge —— 跟信用卡走同一條導轉路徑。
		if ( ! empty( $resp['needs_3ds'] ) ) {
			$payment_url = (string) ( $resp['payment_url'] ?? '' );
			if ( '' !== $payment_url ) {
				$order->update_meta_data( Keys::TAPPAY_PAYMENT_URL, $payment_url );
				$order->update_status( 'pending', __( 'Waiting for the customer to finish the TapPay 3-D Secure check.', 'moksa-for-woocommerce' ) );
				$order->save();
				return [
					'result'   => 'success',
					'redirect' => $payment_url,
				];
			}
		}

		$bank_txn  = (string) ( $resp['data']['bank_transaction_id'] ?? '' );
		$auth_code = (string) ( $resp['data']['auth_code'] ?? '' );
		if ( '' !== $bank_txn ) {
			$order->update_meta_data( Keys::TAPPAY_BANK_TRANSACTION_ID, $bank_txn );
		}
		if ( '' !== $auth_code ) {
			$order->update_meta_data( Keys::TAPPAY_AUTH_CODE, $auth_code );
		}
		$order->update_meta_data( Keys::TAPPAY_TRANSACTION_STATUS, '0' );
		$order->add_order_note(
			sprintf(
				/* translators: 1: payment method name, 2: rec_trade_id */
				__( '%1$s payment completed — transaction ID %2$s', 'moksa-for-woocommerce' ),
				$this->default_title(),
				$rec_trade_id
			)
		);
		$order->payment_complete( $rec_trade_id );
		$order->save();

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
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
