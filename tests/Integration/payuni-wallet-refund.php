<?php
/**
 * 整合測試（測試站 wp eval-file - <訂單ID>）：統一金流愛金卡、AFTEE、LINE Pay、街口退款。
 *
 * 攔截 HTTP：查單回指定的 PaymentType（沒有 CloseStatus），退款 API 依劇本回成功、失敗或斷線，
 * 檢查打到哪支 API、送出的 TradeAmt 與回傳結果。不碰真的金流。
 *
 * 參數：一張統一金流測試訂單（帶 _moksafowo_test，要有交易編號）。直接呼叫 refund()，
 * 不建 WC 退款紀錄，只會多幾則訂單備註。
 *
 * 官方依據（docs.payuni.com.tw/web/#/7/<頁>，G4 複核用）：
 * - page 164 交易查詢：PaymentType 6 愛金卡、7 AFTEE、9 LINE Pay、11 街口；非信用卡沒有 CloseStatus。
 * - page 72 愛金卡退款 trade/common/refund/icash：僅全額退款。
 * - page 84 AFTEE 退款 trade/common/refund/aftee：全額或部分。
 * - page 300 LINE Pay 退款 trade/common/refund/linepay：全額或部分。
 * - page 377 街口支付退款 trade/common/refund/jkopay：全額或部分。
 * - 四支都是 Version 1.0，EncryptInfo 欄位 MerID、TradeNo、TradeAmt（退款金額）、Timestamp；回 RefundNo、RefundDT。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\Payuni\Api\PaymentRequest;
use Moksafowo\Modules\Payuni\PayuniPayment;

$order_id = absint( $args[0] ?? 0 );
$order    = wc_get_order( $order_id );
if ( ! $order || ! $order->get_meta( '_moksafowo_test' ) || 0 !== strpos( (string) $order->get_payment_method(), 'moksafowo_payuni_' ) || ! $order->get_transaction_id() ) {
	WP_CLI::error( '請給一張帶 _moksafowo_test、有交易編號的統一金流測試訂單。' );
}

$order->delete_meta_data( \Moksafowo\Order\Meta\Keys::REFUND_UNKNOWN );
$order->save();

$fail = 0;
$ok   = static function ( bool $pass, string $label ) use ( &$fail ): void {
	if ( $pass ) {
		WP_CLI::log( 'OK  ' . $label );
	} else {
		++$fail;
		WP_CLI::warning( 'NG  ' . $label );
	}
};

/**
 * 跑一個劇本。
 *
 * @param string   $pay_type 查單回的 PaymentType。
 * @param string   $amount   退款金額。
 * @param array    $replies  退款 API 依序回 'ok'、'fail' 或 'down'。
 * @param int|null $wc_left  WC 帳上退完後剩下的金額（預設 300 − 本次）。
 * @return array{0:mixed,1:array<int,string>,2:string}
 */
$run = static function ( string $pay_type, string $amount, array $replies, ?int $wc_left = null ) use ( $order_id ): array {
	$o    = wc_get_order( $order_id );
	$left = $wc_left ?? max( 0, 300 - (int) round( (float) $amount ) );
	$o->set_total( (string) ( (float) $o->get_total_refunded() + $left ) );
	$o->save();
	$calls = [];
	$hook  = static function ( $pre, $parsed, $url ) use ( $pay_type, &$replies, &$calls ) {
		if ( ! preg_match( '#sandbox-api\.payuni\.com\.tw/api/trade/(.+)$#', (string) $url, $m ) ) {
			return $pre;
		}
		$wrap = static function ( string $status, array $info ): array {
			$enc = PayuniPayment::encrypt( $info );
			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'Status' => $status, 'EncryptInfo' => $enc, 'HashInfo' => PayuniPayment::hash_info( $enc ) ] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		};
		if ( 'query' === $m[1] ) {
			$row = [ 'MerTradeNo' => 'T', 'TradeNo' => 'T', 'TradeStatus' => '1', 'PaymentDay' => '', 'CreateDay' => '', 'PaymentType' => $pay_type, 'TradeAmt' => '300' ];
			return $wrap( 'SUCCESS', [ 'Message' => 'ok', 'Result' => [ $row ] ] );
		}
		$sent    = (array) PayuniPayment::decrypt( (string) ( $parsed['body']['EncryptInfo'] ?? '' ) );
		$calls[] = $m[1] . ':' . ( $sent['TradeAmt'] ?? '' );
		$reply   = array_shift( $replies ) ?? 'ok';
		if ( 'down' === $reply ) {
			return new WP_Error( 'http_request_failed', 'simulated timeout' );
		}
		return 'ok' === $reply
			? $wrap( 'SUCCESS', [ 'Status' => 'SUCCESS', 'Message' => '處理成功', 'TradeNo' => 'T', 'RefundNo' => 'RF123', 'RefundDT' => '2026-11-19 12:00:00' ] )
			: $wrap( 'ERROR', [ 'Status' => 'ERROR', 'Message' => '模擬失敗' ] );
	};
	add_filter( 'pre_http_request', $hook, 10, 3 );
	try {
		$result = ( new PaymentRequest() )->refund( $order_id, $amount, 'integration test' );
	} catch ( \Throwable $e ) {
		$result = new WP_Error( 'exception', $e->getMessage() );
	}
	remove_filter( 'pre_http_request', $hook, 10 );
	$notes = wc_get_order_notes( [ 'order_id' => $order_id, 'limit' => 1 ] );
	return [ $result, $calls, $notes ? (string) $notes[0]->content : '' ];
};

$cases = [
	// 標籤, PaymentType, 金額, 回應劇本, 預期步驟, 預期成功, WC 剩下.
	[ 'LINE Pay 部分 100 → common/refund/linepay', '9', '100', [ 'ok' ], [ 'common/refund/linepay:100' ], true ],
	[ '街口 全額 300 → common/refund/jkopay', '11', '300', [ 'ok' ], [ 'common/refund/jkopay:300' ], true ],
	[ 'AFTEE 部分 50 → common/refund/aftee', '7', '50', [ 'ok' ], [ 'common/refund/aftee:50' ], true ],
	[ '愛金卡 全額 300 → common/refund/icash', '6', '300', [ 'ok' ], [ 'common/refund/icash:300' ], true ],
	[ '愛金卡 部分 → 拒絕、不打 API', '6', '100', [], [], false ],
	[ '愛金卡 之前退過、這次退剩下的 → 拒絕（只能一次全額）', '6', '200', [], [], false, 0 ],
	[ 'LINE Pay 退款失敗 → WP_Error', '9', '100', [ 'fail' ], [ 'common/refund/linepay:100' ], false ],
	[ '街口 斷線 → WP_Error（結果未知）', '11', '100', [ 'down' ], [ 'common/refund/jkopay:100' ], false ],
	[ 'ATM → 拒絕、請到後台', '2', '100', [], [], false ],
	[ '超過付款金額 → 拒絕', '9', '400', [], [], false, 0 ],
	[ '有小數 → 拒絕', '9', '100.5', [], [], false ],
];

$orig_total = wc_get_order( $order_id )->get_total();
foreach ( $cases as $case ) {
	[ $label, $type, $amount, $replies, $want_calls, $want_ok ] = $case;
	[ $result, $calls, $note ] = $run( $type, $amount, $replies, $case[6] ?? null );
	$pass = $calls === $want_calls && ( true === $result ) === $want_ok && ( $want_ok || is_wp_error( $result ) );
	if ( $pass && $want_ok ) {
		$pass = false !== strpos( $note, 'RF123' );
	}
	$ok( $pass, $label . ( $pass ? '' : ' → 送出 [' . implode( ', ', $calls ) . '] 回 ' . ( is_wp_error( $result ) ? $result->get_error_message() : var_export( $result, true ) ) . ' 備註 ' . wp_strip_all_tags( $note ) ) );
}

// 斷線那一案（結果未知）要列進退款待處理、擋後續退款；只有那一案。
$unknown = \Moksafowo\Refund\Guard::unknown_list( wc_get_order( $order_id ) );
$ok( 1 === count( $unknown ) && '100' === (string) $unknown[0]['amount'], '斷線 → 列進待處理 1 筆（金額 100）' );

$restore = wc_get_order( $order_id );
$restore->set_total( $orig_total );
$restore->delete_meta_data( \Moksafowo\Order\Meta\Keys::REFUND_UNKNOWN );
$restore->delete_meta_data( \Moksafowo\Order\Meta\Keys::REFUND_OPEN );
$restore->save();
\Moksafowo\Refund\PendingPage::mark( $order_id, false );

0 === $fail ? WP_CLI::success( '全部通過' ) : WP_CLI::error( "{$fail} 項未通過" );
