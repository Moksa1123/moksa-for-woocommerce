<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Api;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Modules\Ecpay\Gateways\CreditBind;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 站內付 2.0 綁卡交易的三個入口：
 * - create：付款頁 JS 送來 BindCardPayToken，建立綁定信用卡交易（35596）。
 * - result：3D 驗證後綠界以幕前 POST 導回（OrderResultURL，35606）。
 * - notify：綠界幕後通知（ReturnURL，35601；續扣的付款結果 40262 也送這裡）。
 *
 * 三者最後都走 apply_result()，以訂單是否已付款做冪等。
 */
final class BindHandler {

	/** 10300066：交易付款結果待確認中（35630）。 */
	private const RTN_PENDING = 10300066;

	/** 綁卡的會員編號：每位會員專屬的隨機值（不可預測、不跨站撞號）。訂閱一定有會員帳號。 */
	public static function member_id( \WC_Order $order ): string {
		return Subscriptions::member_id( $order );
	}

	/** 每次開付款頁都會換新的 MerchantTradeNo；舊分頁、按上一頁的那筆完成付款時也要對得回這張訂單。 */
	public static function remember_mtn( \WC_Order $order, string $mtn ): void {
		$order->update_meta_data( Keys::ECPAY_MERCHANT_TRADE_NO, $mtn );
		$history = $order->get_meta( Keys::ECPAY_BIND_MTN_HISTORY );
		$history = is_array( $history ) ? $history : [];
		if ( ! in_array( $mtn, $history, true ) ) {
			$history[] = $mtn;
			$order->update_meta_data( Keys::ECPAY_BIND_MTN_HISTORY, array_slice( $history, -20 ) );
		}
	}

	/**
	 * 持卡人資料（35591：綁卡 3D 2.0 用，Email 與 Phone 擇一必填）。
	 * 欄位格式不合綠界檢核就整筆建立失敗，所以不合格式的值寧可不帶：
	 * Email 照官方正規表達式、Phone 只留數字且不可有 +、姓名只留中英數與 : , . () / –。
	 */
	public static function consumer_info( \WC_Order $order, string $member_id ): array {
		$email = (string) $order->get_billing_email();
		if ( ! preg_match( '/^[a-zA-Z\d._%+-]+@[a-zA-Z\d.-]+\.[a-zA-Z]{2,6}$/', $email ) || strlen( $email ) > 100 ) {
			$email = '';
		}
		$phone = substr( preg_replace( '/\D+/', '', (string) $order->get_billing_phone() ) ?? '', 0, 20 );
		$name  = (string) preg_replace( '/[^\p{Han}A-Za-z0-9 :,.()\/–-]+/u', '', trim( $order->get_billing_last_name() . $order->get_billing_first_name() ) );
		return array_filter(
			[
				'MerchantMemberID' => $member_id,
				'Email'            => $email,
				'Phone'            => $phone,
				'Name'             => mb_substr( $name, 0, 50 ),
				'CountryCode'      => '158',
			],
			static fn( string $v ): bool => '' !== $v
		);
	}

	/** 商品名稱，多項以 # 分隔，上限 400 字（35630）。 */
	public static function item_name( \WC_Order $order ): string {
		$names = [];
		foreach ( $order->get_items() as $item ) {
			$names[] = str_replace( '#', ' ', (string) $item->get_name() );
		}
		$name = implode( '#', $names );
		if ( '' === $name ) {
			/* translators: %s: site name */
			$name = sprintf( __( '%s order', 'moksa-for-woocommerce' ), get_bloginfo( 'name' ) );
		}
		return mb_substr( $name, 0, 200 );
	}

	public static function create(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 訪客的付款頁沒有登入 nonce；以訂單金鑰（order key）hash_equals 驗證身分，只能動自己那張未付款訂單。
		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$key       = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$pay_token = isset( $_POST['pay_token'] ) ? sanitize_text_field( wp_unslash( $_POST['pay_token'] ) ) : '';
		// phpcs:enable

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) || CreditBind::GATEWAY_ID !== $order->get_payment_method() ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 403 );
		}
		if ( $order->is_paid() ) {
			wp_send_json_success( [ 'redirect' => $order->get_checkout_order_received_url() ] );
		}
		if ( '' === $pay_token ) {
			wp_send_json_error( [ 'message' => __( 'The card details were not received. Please enter the card again.', 'moksa-for-woocommerce' ) ] );
		}

		$resp = Ecpg::create_bind_card( $pay_token, (string) $order->get_meta( Keys::ECPAY_BIND_MEMBER_ID ) );
		$data = $resp['data'];

		$three_d_url = (string) ( $data['ThreeDInfo']['ThreeDURL'] ?? '' );
		if ( '' !== $three_d_url ) {
			wp_send_json_success( [ 'redirect' => esc_url_raw( $three_d_url ) ] );
		}

		if ( ! $resp['ok'] && self::RTN_PENDING !== (int) ( $data['RtnCode'] ?? 0 ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: ECPay message */
					__( 'ECPay could not save the card: %s', 'moksa-for-woocommerce' ),
					$resp['message']
				)
			);
			wp_send_json_error( [ 'message' => '' !== $resp['message'] ? $resp['message'] : __( 'The card payment did not go through. Please check the card and try again.', 'moksa-for-woocommerce' ) ] );
		}

		self::apply_result( $order, $data );
		wp_send_json_success( [ 'redirect' => $order->get_checkout_order_received_url() ] );
	}

	public static function result(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- 綠界幕前導回，無法帶 nonce；內容是只有綠界與特店解得開的 AES 密文，另比對訂單金鑰。
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$raw      = isset( $_POST['ResultData'] ) ? wp_unslash( $_POST['ResultData'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON 字串，解密後再逐欄消毒（Ecpg::decode_envelope）。
		// phpcs:enable

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$envelope = json_decode( (string) $raw, true );
		$data     = is_array( $envelope ) ? Ecpg::decode_envelope( $envelope ) : null;
		$matched  = null !== $data && self::matches_order( $order, $data );
		if ( $matched ) {
			self::apply_result( $order, $data, true );
			$order = wc_get_order( $order_id );
		}

		// 同一筆結果的幕後通知可能正在處理（拿著鎖），綠界回報成功就先帶到訂單完成頁。
		$reported_paid = $matched && 1 === (int) ( $data['RtnCode'] ?? 0 );
		if ( $order->is_paid() || $order->has_status( 'on-hold' ) || $reported_paid ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
		wc_add_notice( __( 'The card payment did not go through. Please check the card and try again.', 'moksa-for-woocommerce' ), 'error' );
		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	public static function notify(): void {
		$raw      = (string) file_get_contents( 'php://input' );
		$envelope = json_decode( $raw, true );
		$data     = is_array( $envelope ) ? Ecpg::decode_envelope( $envelope ) : null;
		if ( null === $data ) {
			Helper::log( 'bind notify rejected: cannot decrypt' );
			status_header( 400 );
			echo '0|Error';
			exit;
		}

		$mtn   = (string) ( $data['OrderInfo']['MerchantTradeNo'] ?? '' );
		$order = self::find_order( $mtn );
		if ( $order ) {
			self::apply_result( $order, $data, true );
		} else {
			Helper::log( 'bind notify: order not found', [ 'mtn' => $mtn ] );
		}
		// 1|OK 只代表收到通知（35601），不影響付款狀態；不回會被重送。
		echo '1|OK';
		exit;
	}

	/**
	 * 把綁卡交易或續扣交易解密後的 Data 寫進訂單。
	 *
	 * 從綠界主動送進來的（ReturnURL、OrderResultURL）只靠 AES 解密確認來源，入帳前再向綠界查一次
	 * 這筆交易（查詢信用卡單筆明細，35662）核對狀態與金額；我們自己呼叫 API 拿到的回應不用再查。
	 */
	public static function apply_result( \WC_Order $order, array $data, bool $verify = false ): void {
		// 建立交易回應、OrderResultURL、ReturnURL 可能同時到，只讓一個處理。
		$lock = 'ecpay-bind-' . $order->get_id();
		if ( ! Subscriptions::lock( $lock ) ) {
			return;
		}
		try {
			$fresh = wc_get_order( $order->get_id() );
			if ( $fresh instanceof \WC_Order && ! $fresh->is_paid() ) {
				self::apply_result_unlocked( $fresh, $data, $verify );
			}
		} finally {
			Subscriptions::unlock( $lock );
		}
	}

	private static function apply_result_unlocked( \WC_Order $order, array $data, bool $verify ): void {
		// 廠商後台「模擬付款」不是真的收款（同既有 IpnHandler）。
		if ( '1' === (string) ( $data['SimulatePaid'] ?? '0' ) ) {
			$order->add_order_note( __( 'ECPay sent a simulated payment notice from its merchant dashboard. The order was not marked as paid.', 'moksa-for-woocommerce' ) );
			return;
		}
		$rtn_code = (int) ( $data['RtnCode'] ?? 0 );
		$info     = is_array( $data['OrderInfo'] ?? null ) ? $data['OrderInfo'] : [];
		$card     = is_array( $data['CardInfo'] ?? null ) ? $data['CardInfo'] : [];
		$trade_no = (string) ( $info['TradeNo'] ?? '' );

		if ( self::RTN_PENDING === $rtn_code ) {
			if ( '' !== $trade_no ) {
				$order->update_meta_data( Keys::ECPAY_TRADE_NO, $trade_no );
			}
			$order->update_status( 'on-hold', __( 'ECPay is still confirming this card payment. Check the payment in the ECPay dashboard before shipping.', 'moksa-for-woocommerce' ) );
			return;
		}
		if ( 1 !== $rtn_code || '1' !== (string) ( $info['TradeStatus'] ?? '' ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: ECPay message, 2: code */
					__( 'The ECPay card payment failed: %1$s (code %2$d)', 'moksa-for-woocommerce' ),
					(string) ( $data['RtnMsg'] ?? '' ),
					$rtn_code
				)
			);
			return;
		}

		// 退款、查詢都要用付款成功那筆的 MerchantTradeNo（可能是較早開的分頁）。
		$paid_mtn = (string) ( $info['MerchantTradeNo'] ?? '' );
		if ( '' !== $paid_mtn ) {
			$order->update_meta_data( Keys::ECPAY_MERCHANT_TRADE_NO, $paid_mtn );
		}
		if ( $verify && ! self::confirmed_by_query( $order, (int) ( $info['TradeAmt'] ?? 0 ) ) ) {
			return;
		}
		$order->update_meta_data( Keys::ECPAY_TRADE_NO, $trade_no );
		$order->update_meta_data( Keys::ECPAY_RTN_CODE, (string) $rtn_code );
		$order->update_meta_data( Keys::ECPAY_PAYMENT_TYPE, 'Credit_CreditCard' );
		$order->update_meta_data( Keys::ECPAY_PAYMENT_DATE, (string) ( $info['PaymentDate'] ?? '' ) );
		if ( '' !== (string) ( $card['Card4No'] ?? '' ) ) {
			$order->update_meta_data( Keys::ECPAY_CARD_LAST4, (string) $card['Card4No'] );
		}
		$order->save();

		// 綁卡交易才會帶 BindCardID；續扣（CreatePaymentWithCardID）沒有，沿用原本的。
		$bind_card_id = (string) ( $data['BindCardID'] ?? '' );
		if ( '' !== $bind_card_id ) {
			Subscriptions::store_payment_meta(
				$order,
				[
					Keys::ECPAY_BIND_CARD_ID   => $bind_card_id,
					Keys::ECPAY_BIND_MEMBER_ID => (string) ( $data['MerchantMemberID'] ?? $order->get_meta( Keys::ECPAY_BIND_MEMBER_ID ) ),
					Keys::ECPAY_CARD_LAST4     => (string) ( $card['Card4No'] ?? '' ),
					Keys::ECPAY_BIND_DECLINED  => '',
				]
			);
		}

		$paid  = (int) ( $info['TradeAmt'] ?? 0 );
		$total = (int) round( (float) $order->get_total() );
		if ( 0 === $total ) {
			// 試用期只綁卡：1 元授權不是要收的錢，依官方流程放棄授權（35536 場景一、35637）。
			$void = Ecpg::do_action( (string) ( $info['MerchantTradeNo'] ?? $order->get_meta( Keys::ECPAY_MERCHANT_TRADE_NO ) ), $trade_no, 'N', $paid );
			if ( ! $void['ok'] ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: amount, 2: ECPay message */
						__( 'The card was saved, but the NT$%1$d card check could not be cancelled automatically: %2$s. Cancel it in the ECPay dashboard.', 'moksa-for-woocommerce' ),
						$paid,
						$void['message']
					)
				);
			}
			$order->payment_complete();
			$order->add_order_note( __( 'The card was saved with ECPay for subscription renewals.', 'moksa-for-woocommerce' ) );
			return;
		}

		if ( $paid !== $total ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: paid amount, 2: order total */
					__( 'ECPay reported a payment of %1$s, which does not match the order total of %2$s. Please check the payment before fulfilling the order.', 'moksa-for-woocommerce' ),
					$paid,
					$total
				)
			);
			return;
		}
		$order->payment_complete( $trade_no );
		$order->add_order_note(
			sprintf(
				/* translators: 1: ECPay trade number, 2: card last four digits */
				__( 'ECPay card payment completed — trade number %1$s (card ending %2$s)', 'moksa-for-woocommerce' ),
				$trade_no,
				(string) ( $card['Card4No'] ?? '' )
			)
		);
	}

	/** 向綠界查這筆交易：銀行已授權（含要關帳、已關帳）且金額相同才算數。 */
	private static function confirmed_by_query( \WC_Order $order, int $amount ): bool {
		$query = Helper::query_credit_trade( $order );
		if ( is_wp_error( $query ) ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: %s: error message */
					__( 'ECPay reported a card payment, but it could not be confirmed with ECPay (%s). Check the payment in the ECPay dashboard before shipping.', 'moksa-for-woocommerce' ),
					$query->get_error_message()
				)
			);
			return false;
		}
		$status = (string) ( $query['RtnValue']['Status'] ?? '' );
		$amt    = (int) ( $query['RtnValue']['Amount'] ?? 0 );
		if ( ! in_array( $status, [ 'Authorized', 'To be captured', 'Captured' ], true ) || $amt !== $amount ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: ECPay status, 2: amount */
					__( 'An ECPay payment notice did not match the transaction at ECPay (status %1$s, amount %2$d), so the order was not marked as paid.', 'moksa-for-woocommerce' ),
					'' !== $status ? $status : '-',
					$amt
				)
			);
			return false;
		}
		return true;
	}

	private static function matches_order( \WC_Order $order, array $data ): bool {
		$mtn = (string) ( $data['OrderInfo']['MerchantTradeNo'] ?? '' );
		return '' !== $mtn && self::order_sent_mtn( $order, $mtn );
	}

	private static function order_sent_mtn( \WC_Order $order, string $mtn ): bool {
		if ( hash_equals( (string) $order->get_meta( Keys::ECPAY_MERCHANT_TRADE_NO ), $mtn ) ) {
			return true;
		}
		$history = $order->get_meta( Keys::ECPAY_BIND_MTN_HISTORY );
		return is_array( $history ) && in_array( $mtn, $history, true );
	}

	private static function find_order( string $mtn ): ?\WC_Order {
		if ( '' === $mtn ) {
			return null;
		}
		$id    = Helper::parse_order_id_from_merchant_trade_no( $mtn );
		$order = $id ? wc_get_order( $id ) : null;
		if ( $order instanceof \WC_Order && self::order_sent_mtn( $order, $mtn ) ) {
			return $order;
		}
		$found = wc_get_orders(
			[
				'limit'      => 1,
				'return'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 依特店交易編號反查訂單；HPOS meta 表有 meta_key 索引。
				'meta_key'   => Keys::ECPAY_MERCHANT_TRADE_NO,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 同上。
				'meta_value' => $mtn,
			]
		);
		$order = ! empty( $found ) ? wc_get_order( (int) $found[0] ) : null;
		return $order instanceof \WC_Order ? $order : null;
	}
}
