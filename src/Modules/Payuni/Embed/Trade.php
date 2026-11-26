<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Payuni\Embed;

use Moksafowo\Modules\Payuni\Api\PaymentRequest;
use Moksafowo\Modules\Payuni\Api\PaymentResponse;
use Moksafowo\Modules\Payuni\Credentials;
use Moksafowo\Modules\Payuni\Gateways\Credit;
use Moksafowo\Modules\Payuni\PayuniPayment;
use Moksafowo\Modules\Payuni\Utils\OrderMeta;
use Moksafowo\Modules\Payuni\Utils\TradeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * 免跳轉的授權：用 session 裡綁好卡號的 SDK token 打 merchant_trade。
 *
 * 前端送來的 getTradeResult 只證明「卡號已綁到 token」；付款成功與否只認 merchant_trade
 * 的回應與背景通知（都先驗 HashInfo），並核對訂單編號與金額。
 */
final class Trade {

	/**
	 * @param \WC_Order $order   訂單。
	 * @param Credit    $gateway 統一金流信用卡（通知網址、返回網址）。
	 * @param string    $enc     getTradeResult 的 EncryptInfo。
	 * @param string    $hash    getTradeResult 的 HashInfo。
	 * @return array{result:string,redirect:string}
	 */
	public static function process( \WC_Order $order, Credit $gateway, string $enc, string $hash ): array {
		// 同一張訂單同時只送一筆授權（兩個分頁同時下單會拿到同一張待付款訂單）。
		$lock = 'moksafowo_pyembed_order_' . $order->get_id();
		if ( ! \Moksafowo\Modules\Shared\Lock::acquire( $lock, 150 ) ) {
			return self::fail( __( 'This order is already being paid. Please wait a moment and check your order before trying again.', 'moksa-for-woocommerce' ) );
		}
		try {
			return self::run( $order, $gateway, $enc, $hash );
		} finally {
			\Moksafowo\Modules\Shared\Lock::release( $lock );
		}
	}

	/**
	 * @param \WC_Order $order   訂單。
	 * @param Credit    $gateway 統一金流信用卡。
	 * @param string    $enc     getTradeResult 的 EncryptInfo。
	 * @param string    $hash    getTradeResult 的 HashInfo。
	 * @return array{result:string,redirect:string}
	 */
	private static function run( \WC_Order $order, Credit $gateway, string $enc, string $hash ): array {
		$order = wc_get_order( $order->get_id() );
		if ( ! $order instanceof \WC_Order ) {
			return self::fail( __( 'The order could not be found', 'moksa-for-woocommerce' ) );
		}
		if ( $order->is_paid() ) {
			return [
				'result'   => 'success',
				'redirect' => $gateway->get_return_url( $order ),
			];
		}
		$entry = Embed::current();
		if ( null === $entry ) {
			return self::fail( __( 'The card form expired. Please enter your card details again.', 'moksa-for-woocommerce' ) );
		}
		if ( ! self::bind_ok( $enc, $hash, $entry['token'] ) ) {
			return self::fail( __( 'The card details could not be sent to PAYUNi. Please check them and try again.', 'moksa-for-woocommerce' ) );
		}
		if ( Credit::renews_subscription( $order ) !== $entry['agree'] ) {
			// 拿 token 之後購物車才加入或拿掉訂閱：約定與否對不上，要換一組 token 重填卡號。
			Embed::forget();
			return self::fail( __( 'Your cart changed. Please enter your card details again.', 'moksa-for-woocommerce' ) );
		}
		$amount = (int) ceil( (float) $order->get_total() );
		if ( $amount < 1 ) {
			return self::fail( __( 'Card payments need an amount of at least NT$1.', 'moksa-for-woocommerce' ) );
		}

		$prod_desc = [];
		foreach ( $order->get_items() as $item ) {
			$prod_desc[] = $item->get_name() . ' * ' . $item->get_quantity();
		}
		$info = [
			'MerID'      => Credentials::merchant_id(),
			'MerTradeNo' => PayuniPayment::build_payuni_order_no( $order->get_id() ),
			'Token'      => $entry['token'],
			'TradeAmt'   => $amount,
			'Timestamp'  => time(),
			'ReturnURL'  => $gateway->return_url,
			'NotifyURL'  => $gateway->notify_url,
			'UsrMail'    => (string) $order->get_billing_email(),
			'ProdDesc'   => mb_substr( implode( ';', $prod_desc ), 0, 500 ),
		];
		$ip   = filter_var( (string) $order->get_customer_ip_address(), FILTER_VALIDATE_IP );
		if ( $ip ) {
			$info['UserIP'] = $ip;
		}
		if ( PayuniPayment::$einvoice_enabled ) {
			// 跳轉流程由顧客在統一金流付款頁填發票；免跳轉沒有那一頁，改用結帳頁的發票欄位。
			$info = array_merge( $info, PaymentRequest::renewal_invoice_args( $order ) );
		}
		if ( '' !== $entry['credit_token'] ) {
			$order->update_meta_data( OrderMeta::EMBED_CREDIT_TOKEN, $entry['credit_token'] );
		}
		$order->update_meta_data( OrderMeta::EMBED, 'yes' );
		$order->save();

		// 送出就作廢：不管結果如何，同一個 token 統一金流都不收第二次。
		Embed::forget();
		// 先排好查單：等授權時 PHP 被主機中止，結果還是會被補判，顧客也不會被當成沒付款再刷一次。
		self::schedule_check( $order->get_id(), 1 );
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 120 );
		}
		$result = Embed::post( 'merchant_trade', $info, '1.0' );
		$data   = $result['data'];
		$status = (string) ( $data['Status'] ?? '' );

		if ( ! $result['verified'] ) {
			// 統一金流明確拒絕（錯誤代碼、沒有加密內容）不會扣款，直接判失敗；連不上或看不懂才是結果不明。
			if ( $result['transport'] && '' !== $result['status'] && ! in_array( $result['status'], [ 'SUCCESS', 'UNKNOWN', 'UNAPPROVED' ], true ) ) {
				return self::declined( $order, $result['status'], '' );
			}
			return self::unknown( $order, __( 'PAYUNi did not return a readable result.', 'moksa-for-woocommerce' ) );
		}
		if ( (string) ( $data['MerTradeNo'] ?? $info['MerTradeNo'] ) !== $info['MerTradeNo'] ) {
			PayuniPayment::warn( 'embed merchant_trade MerTradeNo mismatch for order ' . $order->get_id() );
			return self::unknown( $order, __( 'PAYUNi returned a different order number.', 'moksa-for-woocommerce' ) );
		}

		// 3D：統一金流回驗證頁網址，驗完由通知網址與返回網址處理結果。
		$url = (string) ( $data['URL'] ?? '' );
		if ( 'SUCCESS' === $status && '' !== $url && self::is_payuni_url( $url ) ) {
			$order->add_order_note( __( 'Waiting for the customer to finish the 3-D Secure check (PAYUNi embedded card form).', 'moksa-for-woocommerce' ) );
			self::schedule_check( $order->get_id(), 1 );
			return [
				'result'   => 'success',
				'redirect' => $url,
			];
		}

		$state = (string) ( $data['TradeStatus'] ?? '' );
		if ( 'SUCCESS' === $status && TradeStatus::PAID === $state ) {
			if ( (int) ( $data['TradeAmt'] ?? 0 ) !== $amount ) {
				PayuniPayment::warn( 'embed merchant_trade amount mismatch for order ' . $order->get_id() );
			}
			$fresh = wc_get_order( $order->get_id() );
			if ( $fresh instanceof \WC_Order && ! $fresh->is_paid() ) {
				PaymentResponse::apply_backend_result( $fresh, $data, __( 'PAYUNi payment (card entered at checkout)', 'moksa-for-woocommerce' ) );
			}
			$fresh = wc_get_order( $order->get_id() );
			if ( $fresh instanceof \WC_Order ) {
				self::recover_card( $fresh, (string) ( $data['Card4No'] ?? '' ) );
			}
			if ( function_exists( 'WC' ) && WC()->cart ) {
				WC()->cart->empty_cart();
			}
			return [
				'result'   => 'success',
				'redirect' => $gateway->get_return_url( $order ),
			];
		}

		if ( in_array( $status, [ 'UNKNOWN', 'UNAPPROVED' ], true ) ) {
			return self::unknown( $order, (string) ( $data['Message'] ?? $status ) );
		}

		return self::declined( $order, $status, (string) ( $data['Message'] ?? '' ), (string) ( $data['ResCodeMsg'] ?? '' ) );
	}

	/**
	 * 確定沒扣款：留備註、請顧客換卡或重填。訂單維持待付款，可以再付一次。
	 *
	 * @return array{result:string,redirect:string}
	 */
	private static function declined( \WC_Order $order, string $status, string $message, string $res_msg = '' ): array {
		if ( '' === $message && '' === $res_msg ) {
			$message = __( 'PAYUNi rejected the request', 'moksa-for-woocommerce' );
		}
		$order->add_order_note(
			\Moksafowo\Order\Note::build(
				__( 'The PAYUNi card payment did not go through.', 'moksa-for-woocommerce' ),
				[
					__( 'Status code', 'moksa-for-woocommerce' ) => $status,
					__( 'Message', 'moksa-for-woocommerce' )     => '' !== $res_msg ? $message . ' / ' . $res_msg : $message,
				]
			)
		);
		return self::fail(
			sprintf(
				/* translators: 1: reason from PAYUNi or the bank, 2: code */
				__( 'The card payment did not go through: %1$s (%2$s). Please check the card details or use another card.', 'moksa-for-woocommerce' ),
				'' !== $res_msg ? $res_msg : $message,
				$status
			)
		);
	}

	/** getTradeResult：HashInfo 驗得過、解得開、狀態成功、而且綁的是這個 session 的 token。 */
	private static function bind_ok( string $enc, string $hash, string $token ): bool {
		if ( '' === $enc || '' === $hash || ! hash_equals( PayuniPayment::hash_info( $enc ), strtoupper( $hash ) ) ) {
			return false;
		}
		$data = (array) PayuniPayment::decrypt( $enc );
		return 'SUCCESS' === (string) ( $data['Status'] ?? '' ) && hash_equals( $token, (string) ( $data['Token'] ?? '' ) );
	}

	/** 3D 驗證頁網址來自驗過章的回應，只再要求是 https（驗證頁可能在發卡行的網域）。 */
	private static function is_payuni_url( string $url ): bool {
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && '' !== (string) wp_parse_url( $url, PHP_URL_HOST );
	}

	/** @return array{result:string,redirect:string} */
	private static function fail( string $message ): array {
		wc_add_notice( $message, 'error' );
		return [
			'result'   => 'failure',
			'redirect' => '',
		];
	}

	/**
	 * 結果不明（逾時、驗章失敗、UNKNOWN）：統一金流可能已扣款，不判失敗，保留並排程查單。
	 *
	 * @return array{result:string,redirect:string}
	 */
	private static function unknown( \WC_Order $order, string $reason ): array {
		// 等授權時通知可能已經把訂單入帳，不能把已付款的改回保留中。
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh instanceof \WC_Order && $fresh->is_paid() ) {
			if ( function_exists( 'WC' ) && WC()->cart ) {
				WC()->cart->empty_cart();
			}
			return [
				'result'   => 'success',
				'redirect' => $fresh->get_checkout_order_received_url(),
			];
		}
		$order = $fresh instanceof \WC_Order ? $fresh : $order;
		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: %s: reason */
				__( 'PAYUNi has not confirmed this card payment yet (%s). It will be checked again automatically; do not charge the customer again until then.', 'moksa-for-woocommerce' ),
				$reason
			)
		);
		self::schedule_check( $order->get_id(), 1 );
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_order_received_url(),
		];
	}

	private static function schedule_check( int $order_id, int $attempt ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$args = [ $order_id, $attempt ];
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( Embed::CHECK_HOOK, $args, 'moksa-for-woocommerce' ) ) {
			return;
		}
		as_schedule_single_action( time() + ( 1 === $attempt ? 15 : 60 ) * MINUTE_IN_SECONDS, Embed::CHECK_HOOK, $args, 'moksa-for-woocommerce' );
	}

	/** 結果不明或還在 3D 的訂單：以交易查詢補判，約定信用卡另外查回 CreditHash。 */
	public static function check( $order_id, $attempt = 1 ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || Credit::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}
		// 3D 後由通知入帳的訂閱：通知沒帶 CreditHash 時在這裡補，否則之後續扣全部失敗。
		if ( $order->is_paid() ) {
			self::recover_card( $order, (string) $order->get_meta( OrderMeta::CREDIT_CARD_4NO ) );
			return;
		}
		if ( ! $order->has_status( [ 'on-hold', 'pending' ] ) ) {
			return;
		}
		$query = PaymentRequest::query( $order->get_id(), false );
		$state = is_array( $query ) ? (string) ( $query['TradeStatus'] ?? '' ) : '';
		if ( TradeStatus::PAID === $state || ( $order->has_status( 'on-hold' ) && in_array( $state, [ TradeStatus::FAIL, TradeStatus::CANCEL ], true ) ) ) {
			$info = [
				'Status'      => 'SUCCESS',
				'Message'     => __( 'Confirmed by transaction lookup', 'moksa-for-woocommerce' ),
				'MerTradeNo'  => (string) ( $query['MerTradeNo'] ?? '' ),
				'TradeNo'     => (string) ( $query['TradeNo'] ?? '' ),
				'TradeStatus' => $state,
				'TradeAmt'    => (string) ( $query['TradeAmt'] ?? '' ),
				'PaymentType' => '1',
				'Card4No'     => (string) ( $query['Card4No'] ?? '' ),
			];
			if ( TradeStatus::PAID === $state ) {
				$info = array_merge( $info, self::bound_card( $order, (string) ( $query['Card4No'] ?? '' ) ) );
			}
			PaymentResponse::apply_backend_result( $order, $info, __( 'PAYUNi payment (card entered at checkout)', 'moksa-for-woocommerce' ) );
			return;
		}
		if ( (int) $attempt < 4 ) {
			self::schedule_check( $order->get_id(), (int) $attempt + 1 );
			return;
		}
		if ( $order->has_status( 'on-hold' ) ) {
			$order->add_order_note( __( 'PAYUNi still has not confirmed this card payment. Check it in the PAYUNi dashboard before charging the customer again.', 'moksa-for-woocommerce' ) );
		}
	}

	/** 已付款、要續扣、卻還沒有 CreditHash：查回約定的卡，寫進訂單與訂閱。 */
	private static function recover_card( \WC_Order $order, string $card4 ): void {
		if ( '' !== (string) $order->get_meta( OrderMeta::CREDIT_TOKEN_ID ) || ! Credit::renews_subscription( $order ) ) {
			return;
		}
		$found = self::bound_card( $order, $card4 );
		if ( '' === ( $found['CreditHash'] ?? '' ) ) {
			$order->add_order_note( __( 'The saved card for subscription renewals could not be found at PAYUNi. Renewals will fail until a card is linked to the subscription.', 'moksa-for-woocommerce' ) );
			return;
		}
		$card = [
			OrderMeta::CREDIT_TOKEN_ID   => $found['CreditHash'],
			OrderMeta::CREDIT_TOKEN_LIFE => (string) ( $found['CreditLife'] ?? '' ),
			OrderMeta::CREDIT_CARD_4NO   => $card4,
		];
		foreach ( $card as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		if ( \Moksafowo\Compatibility\Subscriptions::order_has_subscription( $order ) ) {
			\Moksafowo\Compatibility\Subscriptions::store_payment_meta( $order, $card );
		}
		if ( Credit::wps_enabled() && \Moksafowo\Compatibility\WpSubscription::order_has_subscription( $order ) ) {
			\Moksafowo\Compatibility\WpSubscription::store_payment_meta( $order, Credit::GATEWAY_ID, $card );
		}
	}

	/**
	 * 交易查詢不帶 CreditHash：要續扣的訂單用約定時的付款人識別碼查回（/api/credit_bind/query）。
	 *
	 * @return array<string,string>
	 */
	private static function bound_card( \WC_Order $order, string $card4 ): array {
		$credit_token = (string) $order->get_meta( OrderMeta::EMBED_CREDIT_TOKEN );
		if ( '' === $credit_token || '' !== (string) $order->get_meta( OrderMeta::CREDIT_TOKEN_ID ) ) {
			return [];
		}
		$info      = [
			'MerID'           => Credentials::merchant_id(),
			'CreditToken'     => $credit_token,
			'CreditTokenType' => 2,
			'Timestamp'       => time(),
		];
		$encrypted = PayuniPayment::encrypt( $info );
		$response  = wp_remote_post(
			( Credentials::test_mode_enabled() ? 'https://sandbox-api.payuni.com.tw' : 'https://api.payuni.com.tw' ) . '/api/credit_bind/query',
			[
				'timeout' => 20,
				'headers' => [ 'User-Agent' => 'payuni' ],
				'body'    => [
					'MerID'       => $info['MerID'],
					'Version'     => '1.0',
					'EncryptInfo' => $encrypted,
					'HashInfo'    => PayuniPayment::hash_info( $encrypted ),
				],
			]
		);
		if ( is_wp_error( $response ) ) {
			return [];
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! is_string( $body['EncryptInfo'] ?? null ) || ! is_string( $body['HashInfo'] ?? null )
			|| ! hash_equals( PayuniPayment::hash_info( $body['EncryptInfo'] ), strtoupper( $body['HashInfo'] ) ) ) {
			return [];
		}
		$data = map_deep( (array) PayuniPayment::decrypt( $body['EncryptInfo'] ), static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
		foreach ( array_reverse( (array) ( $data['Result'] ?? [] ) ) as $row ) {
			if ( ! is_array( $row ) || '1' !== (string) ( $row['CreditTokenStatus'] ?? '' ) ) {
				continue;
			}
			if ( '' !== $card4 && (string) ( $row['Card4No'] ?? '' ) !== $card4 ) {
				continue;
			}
			return [
				'CreditHash' => (string) ( $row['CreditHash'] ?? '' ),
				'CreditLife' => (string) ( $row['CreditTokenExpired'] ?? '' ),
			];
		}
		return [];
	}
}
