<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Gateways;

use Moksafowo\Modules\Tappay\Api\Client;
use Moksafowo\Modules\Tappay\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Credit extends \WC_Payment_Gateway {

	public const GATEWAY_ID = 'moksafowo_tappay_credit';

	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		$this->has_fields         = true; // 結帳頁要渲染 TapPay Fields。
		$this->method_title       = __( 'TapPay 信用卡', 'moksa-for-woocommerce' );
		$this->method_description = __( '安全信用卡付款，支援 3D 驗證。卡號資訊不流經本站。', 'moksa-for-woocommerce' );
		$this->supports           = [ 'products', 'refunds' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title', __( 'TapPay 信用卡', 'moksa-for-woocommerce' ) );
		$this->description = (string) $this->get_option( 'description', __( '使用信用卡安全付款（由 TapPay 處理）。', 'moksa-for-woocommerce' ) );
		$this->enabled     = (string) $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'     => [
				'title'   => __( '啟用此付款方式', 'moksa-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( '前台顯示名稱', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'TapPay 信用卡', 'moksa-for-woocommerce' ),
				'description' => __( '結帳頁顯示給顧客看的名稱。', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( '前台顯示描述', 'moksa-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => __( '使用信用卡安全付款（由 TapPay 處理）。', 'moksa-for-woocommerce' ),
				'description' => __( '結帳頁付款方式描述。', 'moksa-for-woocommerce' ),
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
		?>
		<div class="moksafowo-tappay-fields" data-moksafowo-tappay-gateway="<?php echo esc_attr( $this->id ); ?>">
			<p class="form-row form-row-wide">
				<label for="moksafowo-tappay-card-number"><?php esc_html_e( '卡號', 'moksa-for-woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
				<span id="moksafowo-tappay-card-number" class="moksafowo-tappay-field input-text"></span>
			</p>
			<p class="form-row form-row-first">
				<label for="moksafowo-tappay-card-expiry"><?php esc_html_e( '有效期限（MM / YY）', 'moksa-for-woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
				<span id="moksafowo-tappay-card-expiry" class="moksafowo-tappay-field input-text"></span>
			</p>
			<p class="form-row form-row-last">
				<label for="moksafowo-tappay-card-ccv"><?php esc_html_e( '安全碼 CVC', 'moksa-for-woocommerce' ); ?>&nbsp;<span class="required">*</span></label>
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
			throw new \Exception( esc_html__( '找不到訂單', 'moksa-for-woocommerce' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC checkout 在上游已驗 nonce；此處僅讀前端取得的一次性 prime
		$prime = isset( $_POST['moksafowo_tappay_prime'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_tappay_prime'] ) ) : '';
		$bin   = isset( $_POST['moksafowo_tappay_bin'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_tappay_bin'] ) ) : '';
		$last4 = isset( $_POST['moksafowo_tappay_last_four'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_tappay_last_four'] ) ) : '';
		$bank  = isset( $_POST['moksafowo_tappay_issuer'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_tappay_issuer'] ) ) : '';
		// phpcs:enable

		if ( '' === $prime ) {
			wc_add_notice( __( '無法取得 TapPay 付款憑證（prime），請重新輸入卡號後再試。', 'moksa-for-woocommerce' ), 'error' );
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}

		// 重試（曾送過 order_number）時加 T{time} 後綴避免 TapPay 拒重複。
		$retry        = '' !== (string) $order->get_meta( Keys::TAPPAY_ORDER_NUMBER );
		$order_number = Helper::build_order_number( $order, $retry );

		$use_3ds    = Helper::three_domain_secure_enabled();
		$result_url = add_query_arg(
			'order_number',
			rawurlencode( $order_number ),
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
			'details'             => $this->build_details( $order ),
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

		if ( ! $resp['ok'] ) {
			$order->update_meta_data( Keys::TAPPAY_TRANSACTION_STATUS, (string) $resp['status'] );
			$order->save();
			wc_add_notice(
				sprintf(
					/* translators: 1: status code, 2: message */
					__( 'TapPay 付款失敗（狀態碼 %1$d）：%2$s', 'moksa-for-woocommerce' ),
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
			$order->update_status( 'pending', __( '等待顧客完成 TapPay 3D 驗證。', 'moksa-for-woocommerce' ) );
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
				__( 'TapPay 信用卡付款完成 — 交易編號 %1$s（卡號末四碼 %2$s）', 'moksa-for-woocommerce' ),
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
			return new \WP_Error( 'moksafowo_tappay_invalid_order', __( '訂單不存在。', 'moksa-for-woocommerce' ) );
		}
		$rec_trade_id = (string) $order->get_meta( Keys::TAPPAY_REC_TRADE_ID );
		if ( '' === $rec_trade_id ) {
			return new \WP_Error( 'moksafowo_tappay_missing_rec_trade_id', __( '訂單缺少 TapPay 交易編號。', 'moksa-for-woocommerce' ) );
		}

		$amt = (int) round( (float) $amount );
		if ( $amt <= 0 ) {
			return new \WP_Error( 'moksafowo_tappay_invalid_amount', __( '退款金額必須大於 0。', 'moksa-for-woocommerce' ) );
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

		if ( ! $result['ok'] ) {
			return new \WP_Error(
				'moksafowo_tappay_refund_fail',
				sprintf(
					/* translators: 1: status code, 2: message */
					__( 'TapPay 退款失敗（狀態碼 %1$d）：%2$s', 'moksa-for-woocommerce' ),
					$result['status'],
					$result['msg']
				)
			);
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: rec_trade_id, 3: reason */
				__( 'TapPay 退款已送出（NT$%1$s，交易編號 %2$s）— %3$s', 'moksa-for-woocommerce' ),
				$amt,
				$rec_trade_id,
				'' !== $reason ? $reason : __( '無原因', 'moksa-for-woocommerce' )
			)
		);
		$order->save();
		return true;
	}

	private function build_details( \WC_Order $order ): string {
		$names = [];
		foreach ( $order->get_items() as $item ) {
			$names[] = (string) $item->get_name();
		}
		$detail = '' !== implode( ',', $names )
			? implode( ',', $names )
			/* translators: %s: site name */
			: sprintf( __( '%s 訂單', 'moksa-for-woocommerce' ), get_bloginfo( 'name' ) );
		return mb_substr( $detail, 0, 100 );
	}
}
