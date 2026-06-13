<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Gateways;

use MoksaWeb\Mowc\Modules\Newebpay\Api\Helper;
use MoksaWeb\Mowc\Modules\Shared\Gateways\AbstractMowcGateway;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

abstract class AbstractNewebpayGateway extends AbstractMowcGateway {

	protected function gateway_supports(): array {
		return [ 'products', 'refunds' ];
	}

	protected function register_receipt_action(): void {
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'render_mpg_form' ] );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'moksafowo_newebpay_invalid_order', __( '訂單不存在。', 'mo-ectools' ) );
		}
		$payment_type = (string) $order->get_meta( Keys::NEWEBPAY_PAYMENT_TYPE );
		$mtn          = (string) $order->get_meta( Keys::NEWEBPAY_MERCHANT_ORDER_NO );
		if ( '' === $mtn ) {
			return new \WP_Error( 'moksafowo_newebpay_missing_mtn', __( '訂單缺少藍新交易編號。', 'mo-ectools' ) );
		}

		$amt_int = (int) ceil( (float) $amount );
		if ( $amt_int <= 0 ) {
			return new \WP_Error( 'moksafowo_newebpay_invalid_amount', __( '退款金額必須大於 0。', 'mo-ectools' ) );
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
			return new \WP_Error( 'moksafowo_newebpay_offline', sprintf(
				/* translators: %s: payment type */
				__( '此付款方式（%s）為線下付款 / 超商，請至藍新後台處理退款。', 'mo-ectools' ),
				$payment_type
			) );
		}

		// 預設走信用卡類 (CREDIT / 分期 / ApplePay / GooglePay / SamsungPay / 紅利 / 銀聯 / Amex)
		return self::do_card_refund( $order, $mtn, $amt_int, (string) $reason );
	}

	private static function do_card_refund( \WC_Order $order, string $mtn, int $amt, string $reason ) {
		// 用 B02 query 看 CloseStatus
		$query = \MoksaWeb\Mowc\Modules\Newebpay\Api\PaymentRequest::query( $mtn, (int) ceil( (float) $order->get_total() ) );
		$close_status = $query['ok'] ? (string) ( $query['data']['CloseStatus'] ?? '0' ) : '0';
		$is_authorized_only = '0' === $close_status;

		$result = $is_authorized_only
			? \MoksaWeb\Mowc\Modules\Newebpay\Api\PaymentRequest::cancel( [ 'Amt' => $amt, 'MerchantOrderNo' => $mtn, 'IndexType' => 1 ] )
			: \MoksaWeb\Mowc\Modules\Newebpay\Api\PaymentRequest::refund( [ 'Amt' => $amt, 'MerchantOrderNo' => $mtn, 'IndexType' => 1 ] );
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			return new \WP_Error( 'moksafowo_newebpay_card_refund_fail', sprintf( __( '藍新退款失敗：%s', 'mo-ectools' ), $result['message'] ) );
		}
		$order->add_order_note( sprintf(
			/* translators: 1: action, 2: amount, 3: reason */
			__( '藍新%1$s成功（NT$%2$s）— %3$s', 'mo-ectools' ),
			$is_authorized_only ? __( '取消授權', 'mo-ectools' ) : __( '退款', 'mo-ectools' ),
			$amt,
			$reason ?: __( '無原因', 'mo-ectools' )
		) );
		$order->save();
		return true;
	}

	private static function do_wallet_refund( \WC_Order $order, string $payment_type, string $mtn, int $amt, string $reason ) {
		$result = \MoksaWeb\Mowc\Modules\Newebpay\Api\PaymentRequest::wallet_refund( [
			'MerchantOrderNo' => $mtn,
			'Amount'          => $amt,
			'PaymentType'     => $payment_type,
		] );
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			return new \WP_Error( 'moksafowo_newebpay_wallet_refund_fail', sprintf( __( '藍新錢包退款失敗：%s', 'mo-ectools' ), $result['message'] ) );
		}
		$order->add_order_note( sprintf(
			/* translators: 1: payment type, 2: amount, 3: reason */
			__( '藍新%1$s 退款成功（NT$%2$s）— %3$s', 'mo-ectools' ),
			self::wallet_label( $payment_type ),
			$amt,
			$reason ?: __( '無原因', 'mo-ectools' )
		) );
		$order->save();
		return true;
	}

	private static function do_bnpl_refund( \WC_Order $order, string $mtn, int $amt, string $reason ) {
		$result = \MoksaWeb\Mowc\Modules\Newebpay\Api\PaymentRequest::bnpl_refund( [
			'MerchantOrderNo' => $mtn,
			'Amt'             => $amt,
			'PaymentType'     => 'AFTEE',
			'Reason'          => $reason ?: __( '一般退貨', 'mo-ectools' ),
		] );
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			return new \WP_Error( 'moksafowo_newebpay_bnpl_refund_fail', sprintf( __( 'AFTEE 退款失敗：%s', 'mo-ectools' ), $result['message'] ) );
		}
		$order->add_order_note( sprintf(
			/* translators: 1: amount, 2: reason */
			__( 'AFTEE 退款成功（NT$%1$s）— %2$s', 'mo-ectools' ),
			$amt,
			$reason ?: __( '無原因', 'mo-ectools' )
		) );
		$order->save();
		return true;
	}

	private static function wallet_label( string $type ): string {
		return [
			'LINEPAY'    => __( 'LINE Pay', 'mo-ectools' ),
			'ESUNWALLET' => __( '玉山 Wallet', 'mo-ectools' ),
			'TAIWANPAY'  => __( '台灣 Pay', 'mo-ectools' ),
			'TWQR'       => 'TWQR',
			'EZPALIPAY'  => __( '支付寶', 'mo-ectools' ),
			'EZPWECHAT'  => __( '微信支付', 'mo-ectools' ),
		][ $type ] ?? $type;
	}

	abstract protected function payment_type_flags(): array;

	protected function extra_params( \WC_Order $order ): array {
		return [];
	}

	private static function build_order_detail( \WC_Order $order ): string {
		$items = [];
		$running_total = 0;
		foreach ( $order->get_items() as $item ) {
			$qty = (int) $item->get_quantity();
			$amt = (int) round( (float) $item->get_total() );  // line total (含折扣)
			$running_total += $amt;
			$items[] = [
				'ItemName'    => mb_substr( (string) $item->get_name(), 0, 20 ),
				'ItemAmt'     => $amt,
				'ItemType'    => 1, // 1=實體商品 / 2=虛擬 / 3=月租 / 4=出貨型
				'ItemOrderNo' => mb_substr( (string) $order->get_id() . '-' . (string) $item->get_id(), 0, 30 ),
			];
		}
		// 運費
		$shipping_total = (int) round( (float) $order->get_shipping_total() );
		if ( $shipping_total > 0 ) {
			$items[] = [
				'ItemName'    => __( '運費', 'mo-ectools' ),
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
				'ItemName'    => __( '稅費 / 折扣調整', 'mo-ectools' ),
				'ItemAmt'     => $expected - $running_total,
				'ItemType'    => 1,
				'ItemOrderNo' => $order->get_id() . '-adj',
			];
		}
		return wp_json_encode( $items, JSON_UNESCAPED_UNICODE ) ?: '';
	}

	
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new \Exception( esc_html__( '找不到訂單', 'mo-ectools' ) );
		}

		$mtn = Helper::generate_merchant_order_no( (int) $order_id );
		$order->update_meta_data( Keys::NEWEBPAY_MERCHANT_ORDER_NO, $mtn );
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
			$order->update_meta_data( Keys::NEWEBPAY_MERCHANT_ORDER_NO, $mtn );
			$order->save();
		}

		$amount    = (int) ceil( (float) $order->get_total() );
		$item_name = mb_substr( $this->build_item_name( $order ), 0, 40 );

		$args = array_merge( [
			'MerchantID'      => Helper::merchant_id(),
			'RespondType'     => 'JSON',
			'TimeStamp'       => (string) time(),
			'Version'         => '2.3',
			'MerchantOrderNo' => $mtn,
			'Amt'             => $amount,
			'ItemDesc'        => $item_name,
			'ReturnURL'       => $order->get_checkout_order_received_url(),
			'NotifyURL'       => home_url( '/wc-api/moksafowo_newebpay_payment' ),
			'CustomerURL'     => $order->get_checkout_order_received_url(),
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
		], $this->payment_type_flags(), $this->extra_params( $order ) );

		$trade_info = Helper::encrypt_trade_info( $args );
		$trade_sha  = Helper::generate_trade_sha( $trade_info );

		$form_data = [
			'MerchantID'  => Helper::merchant_id(),
			'TradeInfo'   => $trade_info,
			'TradeSha'    => $trade_sha,
			'Version'     => '2.3',
			'EncryptType' => 0,
		];

		Helper::log( 'MPG redirect', [
			'order_id'          => $order_id,
			'merchant_order_no' => $mtn,
			'gateway'           => $this->id,
			'amt'               => $amount,
		] );

		$url = Helper::mpg_url();
		?>
		<form method="post" id="moksafowo-newebpay-form" action="<?php echo esc_url( $url ); ?>">
			<?php foreach ( $form_data as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>">
			<?php endforeach; ?>
			<button type="submit" id="moksafowo-newebpay-submit" class="button alt"><?php esc_html_e( '前往藍新付款頁', 'mo-ectools' ); ?></button>
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
		return sprintf( __( '%s 訂單', 'mo-ectools' ), get_bloginfo( 'name' ) ) . ' #' . $order->get_order_number();
	}
}
