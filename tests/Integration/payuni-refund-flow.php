<?php
/**
 * 整合測試（測試站 wp eval-file - <訂單ID>）：統一金流信用卡退款依請款狀態走官方路徑。
 *
 * 沙箱付款後立刻變「請款成功」，請款申請中／未請款／請款取消／請款處理中做不出來，
 * 所以攔截 HTTP：查單回指定的 CloseStatus，close／cancel 依劇本回成功、失敗或斷線，
 * 檢查送出的每一步（API、CloseType、TradeAmt）與回傳結果。不碰真的金流。
 *
 * 參數：一張統一金流信用卡測試訂單（帶 _moksafowo_test，要有交易編號）。直接呼叫 refund()，
 * 不建 WC 退款紀錄，只會多幾則訂單備註。
 *
 * 官方依據（docs.payuni.com.tw/web/#/7/<頁>，G4 複核用）：
 * - page 164 交易查詢：CloseStatus 1 請款申請中、2 請款成功、3 請款取消、7 請款處理中、9 未申請；CloseAmt、AuthType（2 分期、7 銀聯）。
 * - page 38 信用卡請退款 trade/close：CloseType 1 請款、2 退款、-1 取消請款；部分請退款只限一次付清與國外卡，分期、銀聯只能全額。
 * - page 39 取消授權 trade/cancel：已授權未請款的交易。
 * - page 153 請款流程：取消請款後回到未請款，可再取消授權或改請款金額。
 * - page 172：請款處理中的退款先記為預計退款（RefundType=3），請款完成後轉退款申請中。
 * - 正式環境實測（2026-11-19，CloseStatus=1）：CloseType=-1 → trade/cancel 都回 SUCCESS，查詢 TradeStatus 3／CloseStatus 3。
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
 * @param array  $q       查單回的欄位（CloseStatus、CloseAmt、TradeAmt、AuthType）。
 * @param string $amount  退款金額。
 * @param array  $replies close／cancel 依序回 'ok'、'fail' 或 'down'（斷線）。
 * @return array{0:mixed,1:array<int,string>} 回傳值、送出的步驟（例如 close:-1、cancel、close:1:200）。
 */
$run = static function ( array $q, string $amount, array $replies, ?int $wc_left = null ) use ( $order_id ): array {
	// WC 端：refund() 被呼叫時這筆退款已存檔，該請款的是「總額−已退」。這裡直接呼叫 refund()，
	// 所以把訂單總額調成「已退＋預期剩下」來模擬（預設跟金流一致：CloseAmt 或 TradeAmt − 本次）。
	$o     = wc_get_order( $order_id );
	$base  = (float) ( $q['CloseAmt'] ?? 0 ) > 0 ? (float) $q['CloseAmt'] : (float) $q['TradeAmt'];
	$left  = $wc_left ?? max( 0, (int) round( $base - (float) $amount ) );
	$o->set_total( (string) ( (float) $o->get_total_refunded() + $left ) );
	$o->save();
	$calls = [];
	$hook  = static function ( $pre, $parsed, $url ) use ( $q, &$replies, &$calls ) {
		if ( ! preg_match( '#sandbox-api\.payuni\.com\.tw/api/trade/(query|close|cancel)$#', (string) $url, $m ) ) {
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
			$row = $q + [ 'MerTradeNo' => 'T', 'TradeNo' => 'T', 'TradeStatus' => '1', 'PaymentDay' => '', 'CreateDay' => '', 'PaymentType' => '1' ];
			return $wrap( 'SUCCESS', [ 'Message' => 'ok', 'Result' => [ $row ] ] );
		}
		$sent    = (array) PayuniPayment::decrypt( (string) ( $parsed['body']['EncryptInfo'] ?? '' ) );
		$calls[] = rtrim( $m[1] . ':' . ( $sent['CloseType'] ?? '' ) . ':' . ( $sent['TradeAmt'] ?? '' ), ':' );
		$reply   = array_shift( $replies ) ?? 'ok';
		if ( 'down' === $reply ) {
			return new WP_Error( 'http_request_failed', 'simulated timeout' );
		}
		return $wrap( 'ok' === $reply ? 'SUCCESS' : 'ERROR', [ 'Message' => 'ok' === $reply ? '處理成功' : '模擬失敗' ] );
	};
	add_filter( 'pre_http_request', $hook, 10, 3 );
	try {
		$result = ( new PaymentRequest() )->refund( $order_id, $amount, 'integration test' );
	} catch ( \Throwable $e ) {
		$result = new WP_Error( 'exception', $e->getMessage() );
	}
	remove_filter( 'pre_http_request', $hook, 10 );
	return [ $result, $calls ];
};

$base = [ 'CloseAmt' => '300', 'TradeAmt' => '300', 'AuthType' => '1' ];
$cases = [
	// 標籤, 查單, 金額, 回應劇本, 預期步驟, 預期成功.
	[ '請款申請中 全額 → 取消請款＋取消授權', [ 'CloseStatus' => '1' ] + $base, '300', [ 'ok', 'ok' ], [ 'close:-1', 'cancel' ], true ],
	[ '請款申請中 部分 → 取消請款＋只請剩下 200', [ 'CloseStatus' => '1' ] + $base, '100', [ 'ok', 'ok' ], [ 'close:-1', 'close:1:200' ], true ],
	[ '請款申請中（之前已部分）再退 50 → 以 CloseAmt 200 為準請 150', [ 'CloseStatus' => '1', 'CloseAmt' => '200' ] + $base, '50', [ 'ok', 'ok' ], [ 'close:-1', 'close:1:150' ], true ],
	[ '未請款 全額 → 取消授權', [ 'CloseStatus' => '9', 'CloseAmt' => '0' ] + $base, '300', [ 'ok' ], [ 'cancel' ], true ],
	[ '未請款 部分 → 請款 200', [ 'CloseStatus' => '9', 'CloseAmt' => '0' ] + $base, '100', [ 'ok' ], [ 'close:1:200' ], true ],
	[ '請款取消 部分 → 請款 250', [ 'CloseStatus' => '3', 'CloseAmt' => '0' ] + $base, '50', [ 'ok' ], [ 'close:1:250' ], true ],
	[ '請款處理中 → CloseType=2（預計退款）', [ 'CloseStatus' => '7' ] + $base, '100', [ 'ok' ], [ 'close:2:100' ], true ],
	[ '請款成功 → CloseType=2', [ 'CloseStatus' => '2' ] + $base, '100', [ 'ok' ], [ 'close:2:100' ], true ],
	[ '分期 部分 → 拒絕、不打 API', [ 'CloseStatus' => '1', 'AuthType' => '2' ] + $base, '100', [], [], false ],
	[ '銀聯 部分 → 拒絕、不打 API', [ 'CloseStatus' => '9', 'AuthType' => '7', 'CloseAmt' => '0' ] + $base, '100', [], [], false ],
	[ '銀聯 全額 → 取消授權', [ 'CloseStatus' => '9', 'AuthType' => '7', 'CloseAmt' => '0' ] + $base, '300', [ 'ok' ], [ 'cancel' ], true ],
	[ '超過可退 → 拒絕', [ 'CloseStatus' => '9', 'CloseAmt' => '0' ] + $base, '400', [], [], false ],
	[ '取消請款失敗 → 停下', [ 'CloseStatus' => '1' ] + $base, '300', [ 'fail' ], [ 'close:-1' ], false ],
	[ '取消請款成功、重請失敗 → 把原請款送回去', [ 'CloseStatus' => '1' ] + $base, '100', [ 'ok', 'fail', 'ok' ], [ 'close:-1', 'close:1:200', 'close:1:300' ], false ],
	[ '取消請款成功、取消授權斷線 → 不重送（結果未知）', [ 'CloseStatus' => '1' ] + $base, '300', [ 'ok', 'down' ], [ 'close:-1', 'cancel' ], false ],
	[ '請款成功 退款斷線 → WP_Error', [ 'CloseStatus' => '2' ] + $base, '100', [ 'down' ], [ 'close:2:100' ], false ],
	// G4 發現：先部分退 300（請 700），請款後來被取消、CloseAmt 歸零；再退 100 時統一金流會算成 1000−100=900，
	// WC 帳上該請 600。要停下、不送任何請求，不能多扣顧客 300。
	[ '請款取消後 CloseAmt 歸零、跟 WC 帳對不上 → 停下', [ 'CloseStatus' => '3', 'CloseAmt' => '0', 'TradeAmt' => '1000', 'AuthType' => '1' ], '100', [], [], false, 600 ],
];

$orig_total = wc_get_order( $order_id )->get_total();
foreach ( $cases as $case ) {
	[ $label, $q, $amount, $replies, $want_calls, $want_ok ] = $case;
	[ $result, $calls ] = $run( $q, $amount, $replies, $case[6] ?? null );
	$pass = $calls === $want_calls && ( true === $result ) === $want_ok && ( $want_ok || is_wp_error( $result ) );
	$ok( $pass, $label . ( $pass ? '' : ' → 送出 [' . implode( ', ', $calls ) . '] 回 ' . ( is_wp_error( $result ) ? $result->get_error_message() : var_export( $result, true ) ) ) );
}

$restore = wc_get_order( $order_id );
$restore->set_total( $orig_total );
$restore->save();

0 === $fail ? WP_CLI::success( '全部通過' ) : WP_CLI::error( "{$fail} 項未通過" );
