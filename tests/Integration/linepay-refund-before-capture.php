<?php
/**
 * 整合測試（測試站 wp eval-file -）：LINE Pay 先授權後請款、還沒請款時的退款。
 *
 * 建一張帶 _moksafowo_test 的 LINE Pay 測試訂單（總額 300，模擬 confirm 已授權、未請款），
 * 攔截 HTTP：void／capture／refund 依劇本回成功、失敗或斷線，檢查打到哪支 API、金額與結果。不碰真的金流。
 * 測試訂單留著（帶 _moksafowo_test），要刪先問使用者。
 *
 * 官方依據（developers-pay.line.me，G4 複核用）：
 * - online-api capture：/v3/payments/authorizations/{transactionId}/capture，amount 可以跟付款請求的金額不同；
 *   回 1199 或 1280–1298 時這筆付款會自動取消，不要重試。
 * - online-api void：/v3/payments/authorizations/{transactionId}/void，授權後尚未請款才能 void；
 *   已請款的只能 refund。
 * - online-api refund：/v3/payments/{transactionId}/refund，用在已請款的交易。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\Linepay\Api\PaymentRequest;
use Moksafowo\Modules\Linepay\Constants;
use Moksafowo\Modules\Linepay\Gateways\Credit;
use Moksafowo\Order\Meta\Keys;

$fail = 0;
$ok   = static function ( bool $pass, string $label ) use ( &$fail ): void {
	if ( $pass ) {
		WP_CLI::log( 'OK  ' . $label );
	} else {
		++$fail;
		WP_CLI::warning( 'NG  ' . $label );
	}
};

$order = wc_create_order();
$order->set_payment_method( Constants::ID );
$order->set_currency( 'TWD' );
$order->update_meta_data( '_moksafowo_test', 'linepay-refund-before-capture' );
$order->save();
$order_id = $order->get_id();
WP_CLI::log( "測試訂單 #{$order_id}" );

/**
 * 跑一個劇本。
 *
 * @param string $amount   這次退款金額。
 * @param int    $wc_left  WC 帳上退完後剩下的金額。
 * @param array  $replies  LINE Pay 依序回 'ok'、'down' 或 returnCode（例如 '1150'）。
 * @param bool   $authed   是不是「已授權未請款」。
 * @return array{0:mixed,1:array<int,string>,2:string}
 */
$run = static function ( string $amount, int $wc_left, array $replies, bool $authed = true ) use ( $order_id ): array {
	$o = wc_get_order( $order_id );
	$o->set_total( (string) ( (float) $o->get_total_refunded() + $wc_left ) );
	$o->update_meta_data( '_moksafowo_linepay_capture', 'no' );
	$o->update_meta_data( '_moksafowo_linepay_payment_status', $authed ? Constants::PAYMENT_STATUS_AUTHED : Constants::PAYMENT_STATUS_CONFIRMED );
	$o->update_meta_data( Keys::LINEPAY_CONFIRMED, true );
	$o->update_meta_data( '_moksafowo_linepay_reserved_transaction_id', '2026111900000001' );
	$o->set_transaction_id( $authed ? '' : '2026111900000001' );
	$o->set_status( 'on-hold' );
	$o->delete_meta_data( Keys::REFUND_UNKNOWN );
	$o->save();
	$calls = [];
	$hook  = static function ( $pre, $parsed, $url ) use ( &$replies, &$calls ) {
		if ( ! preg_match( '#pay\.line\.me/v3/payments/(?:authorizations/)?\d+/(capture|void|refund)$#', (string) $url, $m ) ) {
			return $pre;
		}
		$body    = json_decode( (string) ( $parsed['body'] ?? '' ), true );
		$amt     = $body['amount'] ?? ( $body['refundAmount'] ?? '' );
		$calls[] = rtrim( $m[1] . ':' . $amt, ':' );
		$reply   = array_shift( $replies ) ?? 'ok';
		if ( 'down' === $reply ) {
			return new WP_Error( 'http_request_failed', 'simulated timeout' );
		}
		$code = 'ok' === $reply ? '0000' : $reply;
		return [
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'returnCode'    => $code,
					'returnMessage' => 'ok' === $reply ? 'Success.' : 'simulated',
					'info'          => [ 'transactionId' => 2026111900000001, 'refundTransactionId' => 2026111900000002 ],
				]
			),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	};
	add_filter( 'pre_http_request', $hook, 10, 3 );
	try {
		$result = ( new PaymentRequest( new Credit() ) )->refund( $order_id, $amount, 'integration test' );
	} catch ( \Throwable $e ) {
		$result = new WP_Error( 'exception', $e->getMessage() );
	}
	remove_filter( 'pre_http_request', $hook, 10 );
	$after = wc_get_order( $order_id );
	return [ $result, $calls, (string) $after->get_meta( '_moksafowo_linepay_payment_status' ), \Moksafowo\Refund\Guard::unknown_list( $after ) ];
};

$cases = [
	// 標籤, 金額, WC 剩下, 劇本, 預期步驟, 預期成功, 預期之後的狀態, 預期列進待處理的金額（'' 不列、total 訂單總額）, 已授權未請款.
	[ '未請款 全額 → void、狀態改取消', '300', 0, [ 'ok' ], [ 'void' ], true, Constants::PAYMENT_STATUS_CANCELLED, '' ],
	[ '未請款 部分 100 → 只請款剩下 200、狀態改已請款', '100', 200, [ 'ok' ], [ 'capture:200' ], true, Constants::PAYMENT_STATUS_CONFIRMED, '' ],
	[ '未請款（之前已在 WC 退 50）再退 100 → 請款 150', '100', 150, [ 'ok' ], [ 'capture:150' ], true, Constants::PAYMENT_STATUS_CONFIRMED, '' ],
	[ 'void 失敗 → WP_Error、狀態不變、不列待處理', '300', 0, [ '1150' ], [ 'void' ], false, Constants::PAYMENT_STATUS_AUTHED, '' ],
	[ 'void 斷線 → 結果未知、列進待處理', '300', 0, [ 'down' ], [ 'void' ], false, Constants::PAYMENT_STATUS_AUTHED, '300' ],
	[ 'capture 失敗（一般）→ WP_Error、狀態不變、不列待處理', '100', 200, [ '1150' ], [ 'capture:200' ], false, Constants::PAYMENT_STATUS_AUTHED, '' ],
	[ 'capture 斷線 → 結果未知、列進待處理', '100', 200, [ 'down' ], [ 'capture:200' ], false, Constants::PAYMENT_STATUS_AUTHED, '100' ],
	[ 'capture 回 1283（整筆被取消）→ 狀態改取消、待處理記訂單總額', '100', 200, [ '1283' ], [ 'capture:200' ], false, Constants::PAYMENT_STATUS_CANCELLED, 'total' ],
	[ '已請款 → 照舊走 refund', '100', 200, [ 'ok' ], [ 'refund:100' ], true, Constants::PAYMENT_STATUS_CONFIRMED, '', false ],
];

foreach ( $cases as $case ) {
	[ $label, $amount, $left, $replies, $want_calls, $want_ok, $want_status, $want_flag ] = $case;
	[ $result, $calls, $status, $flags ] = $run( $amount, $left, $replies, $case[8] ?? true );
	$flag = $flags ? (string) end( $flags )['amount'] : '';
	$want = 'total' === $want_flag ? (string) wc_get_order( $order_id )->get_total() : $want_flag;
	$pass = $calls === $want_calls && ( true === $result ) === $want_ok && ( $want_ok || is_wp_error( $result ) ) && $status === $want_status
		&& ( '' === $want ? empty( $flags ) : ( 1 === count( $flags ) && (float) $flag === (float) $want ) );
	$ok( $pass, $label . ( $pass ? '' : ' → 送出 [' . implode( ', ', $calls ) . '] 狀態 ' . $status . ' 待處理 ' . count( $flags ) . ':' . $flag . ' 回 ' . ( is_wp_error( $result ) ? $result->get_error_message() : var_export( $result, true ) ) ) );
}

// 還沒完成付款（沒有交易編號、也不是已授權未請款）→ 說明沒有可退的交易，不打 API。
$o = wc_get_order( $order_id );
$o->set_transaction_id( '' );
$o->update_meta_data( '_moksafowo_linepay_payment_status', Constants::PAYMENT_STATUS_RESERVED );
$o->save();
$r2 = ( new PaymentRequest( new Credit() ) )->refund( $order_id, '100', 'integration test' );
$ok( is_wp_error( $r2 ) && false !== strpos( $r2->get_error_message(), __( 'This LINE Pay payment has not been completed, so there is nothing to refund through LINE Pay.', 'moksa-for-woocommerce' ) ), '沒完成付款 → 說明沒有可退的交易' );

// 請款按鈕：有結果未知的紀錄時要先擋（Guard::not_ready）。
$o = wc_get_order( $order_id );
$o->update_meta_data( Keys::REFUND_UNKNOWN, [ [ 'amount' => '1', 'time' => time(), 'user' => 0, 'message' => 'test' ] ] );
$o->save();
$ok( null !== \Moksafowo\Refund\Guard::not_ready( wc_get_order( $order_id ) ), '請款按鈕：有結果未知的紀錄 → 擋下' );

// 按鈕請款：之前在 WC 退過的部分不能再向顧客收。
$o = wc_get_order( $order_id );
$o->delete_meta_data( Keys::REFUND_UNKNOWN );
$o->delete_meta_data( Keys::REFUND_OPEN );
\Moksafowo\Refund\PendingPage::mark( $order_id, false );
$o->set_total( '300' );
$o->update_meta_data( '_moksafowo_linepay_payment_status', Constants::PAYMENT_STATUS_AUTHED );
$o->save();
$captured = '';
$hook     = static function ( $pre, $parsed, $url ) use ( &$captured ) {
	if ( ! preg_match( '#/capture$#', (string) $url ) ) {
		return $pre;
	}
	$captured = (string) ( json_decode( (string) $parsed['body'], true )['amount'] ?? '' );
	return [ 'headers' => [], 'body' => wp_json_encode( [ 'returnCode' => '0000', 'returnMessage' => 'Success.', 'info' => [ 'transactionId' => 2026111900000001 ] ] ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
};
add_filter( 'pre_http_request', $hook, 10, 3 );
wc_create_refund( [ 'order_id' => $order_id, 'amount' => 80, 'refund_payment' => false ] );
( new PaymentRequest( new Credit() ) )->capture( wc_get_order( $order_id ) );
remove_filter( 'pre_http_request', $hook, 10 );
$ok( '220' === $captured, '按鈕請款：WC 已退 80 → 只請款 220（實際 ' . $captured . '）' );

0 === $fail ? WP_CLI::success( '全部通過' ) : WP_CLI::error( "{$fail} 項未通過" );
