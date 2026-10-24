<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

use Moksafowo\Modules\Newebpay\Api\Helper;
use Moksafowo\Modules\Shared\Gateways\AbstractMowcGateway;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

abstract class AbstractNewebpayGateway extends AbstractMowcGateway {

	protected function helper_has_credentials(): bool {
		return \Moksafowo\Modules\Newebpay\Api\Helper::has_credentials();
	}

	protected function gateway_supports(): array {
		return [ 'products', 'refunds' ];
	}

	protected function register_receipt_action(): void {
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'render_mpg_form' ] );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'moksafowo_newebpay_invalid_order', __( 'The order does not exist.', 'moksa-for-woocommerce' ) );
		}
		$payment_type = (string) $order->get_meta( Keys::NEWEBPAY_PAYMENT_TYPE );
		$mtn          = (string) $order->get_meta( Keys::NEWEBPAY_MERCHANT_ORDER_NO );
		if ( '' === $mtn ) {
			return new \WP_Error( 'moksafowo_newebpay_missing_mtn', __( 'The order has no NewebPay transaction ID.', 'moksa-for-woocommerce' ) );
		}

		$amt_int = (int) ceil( (float) $amount );
		if ( $amt_int <= 0 ) {
			return new \WP_Error( 'moksafowo_newebpay_invalid_amount', __( 'The refund amount must be greater than 0.', 'moksa-for-woocommerce' ) );
		}

		// 路由：依 PaymentType 走對應 API
		$wallet_types = [ 'ESUNWALLET', 'TAIWANPAY', 'TWQR', 'EZPALIPAY', 'EZPWECHAT' ];
		// LINE Pay 也走 wallet_refund
		if ( 'LINEPAY' === $payment_type || in_array( $payment_type, $wallet_types, true ) ) {
			return self::do_wallet_refund( $order, $payment_type, $mtn, $amt_int, (string) $reason );
		}
		if ( 'AFTEE' === $payment_type ) {
			return self::do_bnpl_refund( $order, $mtn, $amt_int, (string) $reason );
		}
		// ATM/CVS/Barcode/WebATM 不走 API，提示登後台
		$offline_types = [ 'VACC', 'CVS', 'BARCODE', 'WEBATM', 'CVSCOM' ];
		if ( in_array( $payment_type, $offline_types, true ) ) {
			return new \WP_Error(
				'moksafowo_newebpay_offline',
				sprintf(
				/* translators: %s: payment type */
					__( 'This payment method (%s) is offline or paid in store. Please refund it in the NewebPay dashboard.', 'moksa-for-woocommerce' ),
					$payment_type
				)
			);
		}

		// 預設走信用卡類 (CREDIT / 分期 / ApplePay / GooglePay / SamsungPay / 紅利 / 銀聯 / Amex)
		return self::do_card_refund( $order, $mtn, $amt_int, (string) $reason );
	}

	private static function do_card_refund( \WC_Order $order, string $mtn, int $amt, string $reason ) {
		// 用 B02 query 看 CloseStatus（信用卡：0=未請款 1=等待提送請款 2=請款處理中 3=請款完成）。
		// 未請款只能整筆取消授權（金額需與授權金額相同）；請款完成才能退款（同藍新官方外掛）。
		$query = \Moksafowo\Modules\Newebpay\Api\PaymentRequest::query( $mtn, \Moksafowo\Modules\Newebpay\Api\Helper::paid_amount( $order ) );
		if ( ! $query['ok'] ) {
			return new \WP_Error( 'moksafowo_newebpay_query_fail', __( 'Could not confirm the transaction status with NewebPay. Please try the refund again later.', 'moksa-for-woocommerce' ) );
		}
		$close_status       = (string) ( $query['data']['CloseStatus'] ?? '' );
		$auth_amt           = (int) ( $query['data']['Amt'] ?? \Moksafowo\Modules\Newebpay\Api\Helper::paid_amount( $order ) );
		$is_authorized_only = '0' === $close_status;
		if ( $is_authorized_only && $amt !== $auth_amt ) {
			return new \WP_Error( 'moksafowo_newebpay_partial_before_capture', __( 'This NewebPay card payment has not been captured yet, so only the full amount can be refunded now (by cancelling the authorization). For a partial refund, try again after capture completes, usually the next day.', 'moksa-for-woocommerce' ) );
		}
		if ( ! $is_authorized_only && '3' !== $close_status ) {
			return new \WP_Error( 'moksafowo_newebpay_capture_in_progress', __( 'NewebPay is still capturing this card payment. Please refund it after capture completes, usually the next day.', 'moksa-for-woocommerce' ) );
		}

		$result = $is_authorized_only
			? \Moksafowo\Modules\Newebpay\Api\PaymentRequest::cancel(
				[
					'Amt'             => $amt,
					'MerchantOrderNo' => $mtn,
					'IndexType'       => 1,
				]
			)
			: \Moksafowo\Modules\Newebpay\Api\PaymentRequest::refund(
				[
					'Amt'             => $amt,
					'MerchantOrderNo' => $mtn,
					'IndexType'       => 1,
				]
			);
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			return new \WP_Error( 'moksafowo_newebpay_card_refund_fail', sprintf( __( 'The NewebPay refund failed: %s', 'moksa-for-woocommerce' ), $result['message'] ) );
		}
		$order->add_order_note(
			sprintf(
			/* translators: 1: action, 2: amount, 3: reason */
				__( 'NewebPay %1$s succeeded (NT$%2$s) — %3$s', 'moksa-for-woocommerce' ),
				$is_authorized_only ? __( 'Cancel authorization', 'moksa-for-woocommerce' ) : __( 'Refund', 'moksa-for-woocommerce' ),
				$amt,
				$reason ?: __( 'No reason given', 'moksa-for-woocommerce' )
			)
		);
		$order->save();
		return true;
	}

	private static function do_wallet_refund( \WC_Order $order, string $payment_type, string $mtn, int $amt, string $reason ) {
		$result = \Moksafowo\Modules\Newebpay\Api\PaymentRequest::wallet_refund(
			[
				'MerchantOrderNo' => $mtn,
				'Amount'          => $amt,
				'PaymentType'     => $payment_type,
			]
		);
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			return new \WP_Error( 'moksafowo_newebpay_wallet_refund_fail', sprintf( __( 'The NewebPay wallet refund failed: %s', 'moksa-for-woocommerce' ), $result['message'] ) );
		}
		$order->add_order_note(
			sprintf(
			/* translators: 1: payment type, 2: amount, 3: reason */
				__( 'NewebPay %1$s refunded NT$%2$s — %3$s', 'moksa-for-woocommerce' ),
				self::wallet_label( $payment_type ),
				$amt,
				$reason ?: __( 'No reason given', 'moksa-for-woocommerce' )
			)
		);
		$order->save();
		return true;
	}

	private static function do_bnpl_refund( \WC_Order $order, string $mtn, int $amt, string $reason ) {
		$result = \Moksafowo\Modules\Newebpay\Api\PaymentRequest::bnpl_refund(
			[
				'MerchantOrderNo' => $mtn,
				'Amt'             => $amt,
				'PaymentType'     => 'AFTEE',
				'Reason'          => $reason ?: __( 'Standard return', 'moksa-for-woocommerce' ),
			]
		);
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			return new \WP_Error( 'moksafowo_newebpay_bnpl_refund_fail', sprintf( __( 'The AFTEE refund failed: %s', 'moksa-for-woocommerce' ), $result['message'] ) );
		}
		$order->add_order_note(
			sprintf(
			/* translators: 1: amount, 2: reason */
				__( 'AFTEE refunded NT$%1$s — %2$s', 'moksa-for-woocommerce' ),
				$amt,
				$reason ?: __( 'No reason given', 'moksa-for-woocommerce' )
			)
		);
		$order->save();
		return true;
	}

	private static function wallet_label( string $type ): string {
		return [
			'LINEPAY'    => __( 'LINE Pay', 'moksa-for-woocommerce' ),
			'ESUNWALLET' => __( 'E.SUN Wallet', 'moksa-for-woocommerce' ),
			'TAIWANPAY'  => __( 'Taiwan Pay', 'moksa-for-woocommerce' ),
			'TWQR'       => 'TWQR',
			'EZPALIPAY'  => __( 'Alipay', 'moksa-for-woocommerce' ),
			'EZPWECHAT'  => __( 'WeChat Pay', 'moksa-for-woocommerce' ),
		][ $type ] ?? $type;
	}

	abstract protected function payment_type_flags(): array;

	protected function extra_params( \WC_Order $order ): array {
		return [];
	}

	private static function build_order_detail( \WC_Order $order ): string {
		$items         = [];
		$running_total = 0;
		foreach ( $order->get_items() as $item ) {
			$qty            = (int) $item->get_quantity();
			$amt            = (int) round( (float) $item->get_total() );  // line total (含折扣)
			$running_total += $amt;
			$items[]        = [
				'ItemName'    => mb_substr( (string) $item->get_name(), 0, 20 ),
				'ItemAmt'     => $amt,
				'ItemType'    => 1, // 1=一般商品 / 2=票券 / 3=儲值金 / 4=折扣
				'ItemOrderNo' => mb_substr( (string) $order->get_id() . '-' . (string) $item->get_id(), 0, 20 ),
			];
		}
		// 運費
		$shipping_total = (int) round( (float) $order->get_shipping_total() );
		if ( $shipping_total > 0 ) {
			$items[]        = [
				'ItemName'    => __( 'Shipping', 'moksa-for-woocommerce' ),
				'ItemAmt'     => $shipping_total,
				'ItemType'    => 1,
				'ItemOrderNo' => $order->get_id() . '-shipping',
			];
			$running_total += $shipping_total;
		}
		// 對帳：若 running_total 跟 Amt 不同（稅費 / 手續費 / 折價券）→ 加調整 line
		$expected = (int) ceil( (float) $order->get_total() );
		if ( $running_total !== $expected ) {
			$items[] = [
				'ItemName'    => __( 'Tax and discount adjustments', 'moksa-for-woocommerce' ),
				'ItemAmt'     => $expected - $running_total,
				'ItemType'    => $expected < $running_total ? 4 : 1, // 4=折扣
				'ItemOrderNo' => $order->get_id() . '-adj',
			];
		}
		return wp_json_encode( $items, JSON_UNESCAPED_UNICODE ) ?: '';
	}


	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new \Exception( esc_html__( 'The order could not be found', 'moksa-for-woocommerce' ) );
		}

		$mtn = Helper::generate_merchant_order_no( (int) $order_id );
		Helper::remember_mtn( $order, $mtn );
		$order->save();

		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		];
	}

	public function render_mpg_form( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$mtn = (string) $order->get_meta( Keys::NEWEBPAY_MERCHANT_ORDER_NO );
		if ( '' === $mtn ) {
			$mtn = Helper::generate_merchant_order_no( $order_id );
			Helper::remember_mtn( $order, $mtn );
			$order->save();
		}

		$amount    = (int) ceil( (float) $order->get_total() );
		$item_name = mb_substr( $this->build_item_name( $order ), 0, 40 );

		$args = array_merge(
			[
				'MerchantID'      => Helper::merchant_id(),
				'RespondType'     => 'JSON',
				'TimeStamp'       => (string) time(),
				'Version'         => '2.3',
				'MerchantOrderNo' => $mtn,
				'Amt'             => $amount,
				'ItemDesc'        => $item_name,
				'ReturnURL'       => $order->get_checkout_order_received_url(),
				'NotifyURL'       => home_url( '/wc-api/moksafowo_newebpay_payment' ),
				'CustomerURL'     => home_url( '/wc-api/moksafowo_newebpay_customer' ),
				'ClientBackURL'   => $order->get_cancel_order_url_raw(),
				'Email'           => $order->get_billing_email(),
				'EmailModify'     => 0,
				'LoginType'       => 0,
				// OrderDetail（per NDNF 1.2.2 — AFTEE 必填，其他選填提升結帳頁體驗）
				'OrderDetail'     => self::build_order_detail( $order ),
				// 預設全部 payment type flag 關閉，子類 payment_type_flags() 開對應的
				'CREDIT'          => 0,
				'WEBATM'          => 0,
				'VACC'            => 0,
				'CVS'             => 0,
				'BARCODE'         => 0,
				'APPLEPAY'        => 0,
				'ANDROIDPAY'      => 0,
				'SAMSUNGPAY'      => 0,
				'LINEPAY'         => 0,
				'AFTEE'           => 0,
				'InstFlag'        => 0,
			],
			$this->payment_type_flags(),
			$this->extra_params( $order )
		);

		$trade_info = Helper::encrypt_trade_info( $args );
		$trade_sha  = Helper::generate_trade_sha( $trade_info );

		$form_data = [
			'MerchantID'  => Helper::merchant_id(),
			'TradeInfo'   => $trade_info,
			'TradeSha'    => $trade_sha,
			'Version'     => '2.3',
			'EncryptType' => 0,
		];

		Helper::log(
			'MPG redirect',
			[
				'order_id'          => $order_id,
				'merchant_order_no' => $mtn,
				'gateway'           => $this->id,
				'amt'               => $amount,
			]
		);

		$url = Helper::mpg_url();
		?>
		<form method="post" id="moksafowo-newebpay-form" action="<?php echo esc_url( $url ); ?>">
			<?php foreach ( $form_data as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>">
			<?php endforeach; ?>
			<button type="submit" id="moksafowo-newebpay-submit" class="button alt"><?php esc_html_e( 'Pay with NewebPay', 'moksa-for-woocommerce' ); ?></button>
		</form>
		<?php wp_print_inline_script_tag( 'document.getElementById("moksafowo-newebpay-form").submit();' ); ?>
		<?php
	}

	protected function build_item_name( \WC_Order $order ): string {
		$admin_name = trim( (string) get_option( 'moksafowo_newebpay_payment_item_name', '' ) );
		if ( '' !== $admin_name ) {
			return $admin_name;
		}
		foreach ( $order->get_items() as $item ) {
			return (string) $item->get_name();
		}
		/* translators: %s: site name */
		return sprintf( __( '%s order', 'moksa-for-woocommerce' ), get_bloginfo( 'name' ) ) . ' #' . $order->get_order_number();
	}
}
