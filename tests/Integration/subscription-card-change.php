<?php
/**
 * 整合測試（測試站 wp eval-file - <會員ID>）：顧客自行換卡（綠界綁定信用卡、TapPay 開關與次數上限）。
 *
 * 建一個帶 _moksafowo_test 的訂閱（屬於傳入的測試會員，原本用綠界綁定卡 OLD），
 * 攔截 HTTP（查單 QueryTrade、放棄授權 Credit/DoAction），直接把綠界的結果交給 BindHandler::apply_result，
 * 檢查：綁卡成功才切換付款方式、驗卡授權有放棄、冪等、偽造或對不上的結果不動訂閱。不碰真的金流。
 * 測試訂閱留著（帶 _moksafowo_test），要刪先問使用者。
 *
 * 官方依據（developers.ecpay.com.tw，G4 複核用）：
 * - 35536 綁定信用卡應用場景一：只綁卡時以小額授權綁卡，成功後放棄授權。
 * - 35662 查詢信用卡單筆明細：Status（Authorized／To be captured／Captured）、Amount。
 * - 35637／2885 信用卡請退款 DoAction：N 放棄（已授權未關帳）。
 * - 35601 ReturnURL、35606 OrderResultURL：Data 以 AES 加密，SimulatePaid=1 是後台模擬付款。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Modules\Ecpay\Api\BindHandler;
use Moksafowo\Modules\Ecpay\Api\Helper as EcpayHelper;
use Moksafowo\Modules\Ecpay\Gateways\CreditBind;
use Moksafowo\Modules\Tappay\Gateways\Credit as TappayCredit;
use Moksafowo\Order\Meta\Keys;

$user_id = absint( $args[0] ?? 0 );
if ( ! $user_id || ! get_userdata( $user_id ) || ! Subscriptions::active() ) {
	WP_CLI::error( '請給測試會員 ID，且要啟用 WooCommerce Subscriptions。' );
}
wp_set_current_user( $user_id );

$fail = 0;
$ok   = static function ( bool $pass, string $label ) use ( &$fail ): void {
	if ( $pass ) {
		WP_CLI::log( 'OK  ' . $label );
	} else {
		++$fail;
		WP_CLI::warning( 'NG  ' . $label );
	}
};

// 次數上限的名額鎖先清掉（同一會員反覆跑測試）。
foreach ( [ CreditBind::GATEWAY_ID, TappayCredit::GATEWAY_ID ] as $gw ) {
	for ( $n = 1; $n <= 3; $n++ ) {
		Subscriptions::unlock( 'card-change-' . $gw . '-' . $user_id . '-' . $n );
	}
}

$sub = wcs_create_subscription(
	[
		'customer_id'      => $user_id,
		'billing_period'   => 'month',
		'billing_interval' => 1,
		'start_date'       => gmdate( 'Y-m-d H:i:s' ),
	]
);
$sub->add_product( wc_get_product( 5412 ), 1 );
$sub->set_payment_method( 'moksafowo_tappay_credit' );
$sub->update_meta_data( '_moksafowo_test', 'subscription-card-change' );
$sub->calculate_totals();
$sub->save();
$sub_id = $sub->get_id();
WP_CLI::log( "測試訂閱 #{$sub_id}" );

// ---------- WCS 預設先切付款方式：我們的金流要延後 ----------
$ok( false === apply_filters( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', true, CreditBind::GATEWAY_ID, $sub ), '綠界：WCS 不會在綁卡前先切換付款方式' );
$ok( false === apply_filters( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', true, TappayCredit::GATEWAY_ID, $sub ), 'TapPay：同上' );
$ok( true === apply_filters( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', true, 'bacs', $sub ), '其他付款方式照 WCS 預設' );

// ---------- 綠界：process_payment（換卡入口）----------
$gw = WC()->payment_gateways()->payment_gateways()[ CreditBind::GATEWAY_ID ];
$ok( $gw->supports( 'subscription_payment_method_change_customer' ), '綠界：開放顧客換卡' );
$r = $gw->process_payment( $sub_id );
$ok( 'success' === $r['result'] && false !== strpos( $r['redirect'], 'moksafowo_ecpay_card_change=1' ), '綠界：送出後帶到換卡表單' );
$ok( CreditBind::change_pending( wc_get_order( $sub_id ) ), '綠界：換卡有效期限已設定' );
$ok( 'moksafowo_tappay_credit' === wc_get_order( $sub_id )->get_payment_method(), '綠界：還沒綁卡，付款方式不變' );

// 次數上限算在送出卡號（create 端點），開表單不扣；這裡驗共用的名額鎖。
$slots = [];
for ( $n = 1; $n <= 4; $n++ ) {
	$slots[] = Subscriptions::take_card_change_slot( $user_id, CreditBind::GATEWAY_ID );
}
$ok( [ true, true, true, false ] === $slots, '名額鎖：同一會員一天第 4 次換卡被擋' );
for ( $n = 1; $n <= 3; $n++ ) {
	Subscriptions::unlock( 'card-change-' . CreditBind::GATEWAY_ID . '-' . $user_id . '-' . $n );
}

// 別人的訂閱不能用。
wp_set_current_user( 1 );
$r5 = $gw->process_payment( $sub_id );
$ok( 'failure' === $r5['result'], '綠界：不是訂閱本人不能換卡' );
wp_set_current_user( $user_id );

// ---------- 綠界：結果處理（攔截查單、放棄授權）----------
$http = static function ( array $json ): array {
	return [ 'headers' => [], 'body' => wp_json_encode( $json ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
};
$enc  = static function ( array $data ): string {
	return (string) openssl_encrypt( urlencode( (string) wp_json_encode( $data ) ), 'aes-128-cbc', EcpayHelper::hash_key(), 0, EcpayHelper::hash_iv() );
};

/**
 * 跑一次結果。
 *
 * @param array  $data      綠界解密後的 Data。
 * @param string $q_status  查單回的 Status。
 * @param int    $q_amount  查單回的 Amount。
 * @param string $void      放棄授權回 ok／fail。
 * @return array<int,string> 放棄授權送出的步驟。
 */
$apply = static function ( array $data, string $q_status, int $q_amount, string $void = 'ok' ) use ( $sub_id, $http, $enc ): array {
	$calls = [];
	$hook  = static function ( $pre, $parsed, $url ) use ( $q_status, $q_amount, $void, &$calls, $http, $enc ) {
		if ( false !== strpos( (string) $url, 'QueryTrade' ) ) {
			return $http( [ 'TransCode' => 1, 'Data' => $enc( [ 'RtnCode' => 1, 'RtnValue' => [ 'Status' => $q_status, 'Amount' => $q_amount ] ] ) ] );
		}
		if ( false !== strpos( (string) $url, 'Credit/DoAction' ) ) {
			$body    = json_decode( (string) $parsed['body'], true );
			$calls[] = 'DoAction';
			return $http( [ 'TransCode' => 1, 'Data' => $enc( [ 'RtnCode' => 'ok' === $void ? 1 : 10200047, 'RtnMsg' => 'ok' === $void ? 'OK' : 'fail' ] ) ] );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $hook, 10, 3 );
	BindHandler::apply_result( wc_get_order( $sub_id ), $data, true );
	remove_filter( 'pre_http_request', $hook, 10 );
	return $calls;
};

$s = wc_get_order( $sub_id );
$s->update_meta_data( Keys::ECPAY_CHANGE_MEMBER, 'M' . $user_id );
foreach ( [ 'CHG1', 'CHG2', 'CHG3', 'CHG4', 'CHG5', 'CHG6', 'CHG7' ] as $m ) {
	BindHandler::remember_change_mtn( $s, $m );
}
$s->save();
$amt  = CreditBind::verify_amount();
$good = static function ( string $mtn, string $card ) use ( $amt ): array {
	return [
		'RtnCode'          => 1,
		'RtnMsg'           => 'Success',
		'MerchantMemberID' => 'MX',
		'BindCardID'       => $card,
		'OrderInfo'        => [ 'MerchantTradeNo' => $mtn, 'TradeNo' => 'T' . $mtn, 'TradeAmt' => $amt, 'TradeStatus' => '1' ],
		'CardInfo'         => [ 'Card4No' => '4242' ],
	];
};
$state = static function () use ( $sub_id ): array {
	$o = wc_get_order( $sub_id );
	return [ $o->get_payment_method(), (string) $o->get_meta( Keys::ECPAY_BIND_CARD_ID ) ];
};

$apply( [ 'SimulatePaid' => '1' ] + $good( 'CHG1', 'NEW1' ), 'Authorized', $amt );
$ok( [ 'moksafowo_tappay_credit', '' ] === $state(), '後台模擬付款 → 不動訂閱' );

$apply( $good( 'FAKE9', 'NEW1' ), 'Authorized', $amt );
$ok( [ 'moksafowo_tappay_credit', '' ] === $state(), '不是這個訂閱送出的編號 → 不動訂閱' );

$apply( [ 'RtnCode' => 10100248, 'RtnMsg' => 'declined' ] + $good( 'CHG1', 'NEW1' ), 'Authorized', $amt );
$ok( [ 'moksafowo_tappay_credit', '' ] === $state() && ! CreditBind::change_pending( wc_get_order( $sub_id ) ), '驗卡失敗 → 不動訂閱、這次換卡期限結束' );

$calls = $apply( $good( 'CHG2', 'NEW1' ), 'Authorized', $amt + 100 );
$ok( [ 'moksafowo_tappay_credit', '' ] === $state() && [ 'DoAction' ] === $calls, '查單金額對不上 → 不動訂閱，但照樣放棄可能已授權的金額' );

$calls = $apply( $good( 'CHG3', 'NEW1' ), 'Failed', $amt );
$ok( [ 'moksafowo_tappay_credit', '' ] === $state() && [ 'DoAction' ] === $calls, '查單不是已授權 → 不動訂閱、嘗試放棄授權' );

$calls = $apply( $good( 'CHG4', 'NEW1' ), 'Authorized', $amt );
$ok( [ CreditBind::GATEWAY_ID, 'NEW1' ] === $state() && [ 'DoAction' ] === $calls, '驗卡成功 → 寫入新卡、切到綠界、放棄驗卡授權' );

$calls = $apply( $good( 'CHG4', 'NEW2' ), 'Authorized', $amt );
$ok( [ CreditBind::GATEWAY_ID, 'NEW1' ] === $state() && [] === $calls, '同一筆結果再送一次 → 不重複處理' );

// 放棄授權失敗：照樣換好，但要提醒店長（留備註）。
$apply( $good( 'CHG5', 'NEW2' ), 'Authorized', $amt, 'fail' );
$notes = wc_get_order_notes( [ 'order_id' => $sub_id, 'limit' => 1 ] );
$ok( [ CreditBind::GATEWAY_ID, 'NEW2' ] === $state() && $notes && false !== strpos( $notes[0]->content, 'TCHG5' ), '放棄授權失敗 → 卡照樣換好、備註提醒到綠界後台取消' );

// 訂閱已經結束才收到結果：不換卡，只放棄授權。
$s = wc_get_order( $sub_id );
$s->update_status( 'cancelled' );
$calls = $apply( $good( 'CHG6', 'NEW3' ), 'Authorized', $amt );
$ok( 'NEW2' === $state()[1] && [ 'DoAction' ] === $calls, '訂閱已結束 → 不換卡、放棄授權' );
// 把訂閱換回原狀態給後面的 SLP 項目用（取消的訂閱 WCS 不能直接改回，改用新的測試訂閱）。
$sub2 = wcs_create_subscription(
	[
		'customer_id'      => $user_id,
		'billing_period'   => 'month',
		'billing_interval' => 1,
		'start_date'       => gmdate( 'Y-m-d H:i:s' ),
	]
);
$sub2->add_product( wc_get_product( 5412 ), 1 );
$sub2->set_payment_method( CreditBind::GATEWAY_ID );
$sub2->update_meta_data( '_moksafowo_test', 'subscription-card-change' );
$sub2->calculate_totals();
$sub2->save();
$sub_id = $sub2->get_id();
WP_CLI::log( "SLP 用的測試訂閱 #{$sub_id}" );

// ---------- Shopline Payments ----------
// 用真的 webhook 網址送簽章正確的事件（站內 HTTP），走完整的驗簽→找訂單→換卡流程。
use Moksafowo\Modules\ShoplinePayments\Api\Helper as SlpHelper;
use Moksafowo\Modules\ShoplinePayments\Gateways\CardGateway as SlpCard;

if ( '' === SlpHelper::sign_key() ) {
	WP_CLI::warning( '測試站沒有 Shopline Payments 簽章金鑰，略過 SLP 項目。' );
} else {
	$slp = WC()->payment_gateways()->payment_gateways()[ SlpCard::GATEWAY_ID ];
	$ok( $slp->supports( 'subscription_payment_method_change_customer' ), 'SLP：開放顧客換卡' );
	for ( $n = 1; $n <= 3; $n++ ) {
		Subscriptions::unlock( 'card-change-' . SlpCard::GATEWAY_ID . '-' . $user_id . '-' . $n );
	}
	wc_clear_notices();
	$r = $slp->process_payment( $sub_id );
	$ok( 'success' === $r['result'] && false !== strpos( $r['redirect'], 'moksafowo_slp_card_change=1' ) && SlpCard::change_pending( wc_get_order( $sub_id ) ), 'SLP：送出後帶到換卡表單、期限已設定' );

	$s = wc_get_order( $sub_id );
	$s->update_meta_data( Keys::SLP_CHANGE_REFS, [ $sub_id . '-1001', $sub_id . '-1002', $sub_id . '-1003', $sub_id . '-1004', $sub_id . '-1005' ] );
	$s->save();

	$send = static function ( string $type, array $data, string $event_id ): int {
		$body = (string) wp_json_encode(
			[
				'id'   => $event_id,
				'type' => $type,
				'data' => $data,
			]
		);
		$ts   = (string) (int) round( microtime( true ) * 1000 );
		$resp = wp_remote_post(
			home_url( '/wc-api/moksafowo_shopline_payments' ),
			[
				'timeout'   => 30,
				'sslverify' => false,
				'headers'   => [
					'Content-Type' => 'application/json',
					'sign'         => hash_hmac( 'sha256', $ts . '.' . $body, SlpHelper::sign_key() ),
					'timestamp'    => $ts,
					'apiVersion'   => 'V1',
				],
				'body'      => $body,
			]
		);
		return (int) wp_remote_retrieve_response_code( $resp );
	};
	$event = static function ( string $ref, string $behavior, string $instrument ): array {
		return [
			'referenceOrderId' => $ref,
			'tradeOrderId'     => 'TO' . $ref,
			'status'           => 'SUCCEEDED',
			'order'            => [ 'customer' => [ 'customerId' => 'SLPCUST' ] ],
			'payment'          => [
				'paymentBehavior'   => $behavior,
				'paymentInstrument' => [ 'paymentInstrumentId' => $instrument ],
				'creditCard'        => [ 'last4' => '1881' ],
				'paidAmount'        => [ 'value' => 100 ],
			],
		];
	};
	$slp_state = static function () use ( $sub_id ): array {
		// webhook 在另一個請求裡改了訂閱，這個行程的物件快取是舊的。
		wp_cache_flush();
		$o = wc_get_order( $sub_id );
		return [ $o->get_payment_method(), (string) $o->get_meta( Keys::SLP_INSTRUMENT_ID ), $o->get_status() ];
	};
	$before = $slp_state();

	$send( 'trade.succeeded', $event( $sub_id . '-9999', 'CardBind', 'INSTX' ), 'ev-' . wp_generate_password( 8, false ) );
	$ok( $before === $slp_state(), 'SLP：不是這個訂閱送出的編號 → 不動訂閱' );

	$send( 'trade.failed', $event( $sub_id . '-1001', 'CardBind', '' ), 'ev-' . wp_generate_password( 8, false ) );
	$ok( $before === $slp_state(), 'SLP：驗卡失敗 → 不動訂閱' );

	$send( 'trade.succeeded', $event( $sub_id . '-1002', 'CardBind', '' ), 'ev-' . wp_generate_password( 8, false ) );
	$ok( $before === $slp_state(), 'SLP：成功但沒有付款工具 → 不動訂閱' );

	$send( 'trade.succeeded', $event( $sub_id . '-1003', 'Regular', 'INST1' ), 'ev-' . wp_generate_password( 8, false ) );
	$ok( $before === $slp_state(), 'SLP：不是綁卡交易 → 不動訂閱' );

	$code = $send( 'trade.succeeded', $event( $sub_id . '-1004', 'CardBind', 'INST1' ), 'ev-ok1' );
	$after = $slp_state();
	$ok( 200 === $code && SlpCard::GATEWAY_ID === $after[0] && 'INST1' === $after[1] && $before[2] === $after[2], 'SLP：綁卡成功 → 寫入新付款工具、切到 SLP、訂閱狀態不變（' . implode( '/', $after ) . '）' );
	$ok( ! SlpCard::change_pending( wc_get_order( $sub_id ) ), 'SLP：成功後換卡期限清掉' );

	$send( 'trade.succeeded', $event( $sub_id . '-1004', 'CardBind', 'INST2' ), 'ev-ok2' );
	$ok( 'INST1' === $slp_state()[1], 'SLP：同一筆換卡再送一次 → 不重複處理' );

	$ts  = (string) (int) round( microtime( true ) * 1000 );
	$bad = wp_remote_post(
		home_url( '/wc-api/moksafowo_shopline_payments' ),
		[
			'sslverify' => false,
			'headers'   => [
				'Content-Type' => 'application/json',
				'sign'         => str_repeat( 'a', 64 ),
				'timestamp'    => $ts,
			],
			'body'      => (string) wp_json_encode( [ 'id' => 'ev-x', 'type' => 'trade.succeeded', 'data' => $event( $sub_id . '-1005', 'CardBind', 'EVIL' ) ] ),
		]
	);
	$ok( 401 === (int) wp_remote_retrieve_response_code( $bad ) && 'INST1' === $slp_state()[1], 'SLP：偽造簽章 → 401、不動訂閱' );
}

// ---------- TapPay：預設關閉 ----------
$tp = WC()->payment_gateways()->payment_gateways()[ TappayCredit::GATEWAY_ID ];
$ok( ! TappayCredit::customer_card_change_enabled() && ! $tp->supports( 'subscription_payment_method_change_customer' ), 'TapPay：預設不開放顧客換卡' );
// 變更付款方式表單會帶 _wcsnonce（CheckoutInput 驗）。
$_POST['moksafowo_tappay_prime'] = 'test_prime';
$_POST['_wcsnonce']              = wp_create_nonce( 'wcs_change_payment_method' );
$_REQUEST                        = array_merge( $_REQUEST, $_POST );
wc_clear_notices();
$rt = $tp->process_payment( $sub_id );
unset( $_POST['moksafowo_tappay_prime'], $_POST['_wcsnonce'] );
$errs = wp_list_pluck( wc_get_notices( 'error' ), 'notice' );
$ok( 'failure' === $rt['result'] && false !== strpos( implode( ' ', $errs ), __( 'Changing the card yourself is not available for this payment method. Please contact the store.', 'moksa-for-woocommerce' ) ), 'TapPay：沒開放時直接送出也擋下（' . wp_strip_all_tags( implode( ' ', $errs ) ) . '）' );

wc_clear_notices();
0 === $fail ? WP_CLI::success( '全部通過' ) : WP_CLI::error( "{$fail} 項未通過" );
