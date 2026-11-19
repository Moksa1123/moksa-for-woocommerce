<?php
/**
 * 整合測試（測試站 wp eval-file - <綠界測試單> <藍新測試單>）：綠界、藍新信用卡退款依請款狀態走官方路徑。
 *
 * 綠界 DoAction 只有正式環境、藍新沙箱付款頁擋自動化瀏覽器，請款中的狀態做不出來，
 * 所以攔截 HTTP：查單回指定狀態，請退款 API 依劇本回成功或失敗，檢查送出的每一步。
 * 兩張單都要帶 _moksafowo_test；腳本會補上查單需要的交易編號 meta（測試單專用值）。
 * 直接呼叫 process_refund()，不建 WC 退款紀錄，只會多幾則訂單備註。
 *
 * 官方依據（G4 複核用）：
 * - 綠界：信用卡請退款 CreditDetail/DoAction（developers.ecpay.com.tw/2885，Action C 關帳／R 退刷／E 取消關帳／N 放棄；
 *   只有正式環境）、請退款流程（/35637：已授權只能 N；要關帳全額 E 再 N、部分 R；已關帳 R）、
 *   查詢信用卡單筆明細 CreditDetail/QueryTrade/V2（/2894，Status 已授權／要關帳／已關帳）。
 * - 藍新：《線上交易─幕前支付技術串接手冊》NDNF-1.2.6（2026-10-01）——單筆交易查詢 B02（CloseStatus 0 未請款、
 *   1 等待提送請款、2 請款處理中、3 請款完成；CloseAmt、Inst）、取消授權 /API/CreditCard/Cancel、
 *   請退款 /API/CreditCard/Close（B031 請款 CloseType=1、B032 退款 CloseType=2、B033 取消請款 CloseType=1＋Cancel=1；
 *   分期只能整筆）。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\Ecpay\Api\Helper as EcpayHelper;
use Moksafowo\Modules\Newebpay\Api\Helper as NewebHelper;
use Moksafowo\Order\Meta\Keys;

$ecpay_id = absint( $args[0] ?? 0 );
$neweb_id = absint( $args[1] ?? 0 );
foreach ( [ $ecpay_id, $neweb_id ] as $id ) {
	$o = wc_get_order( $id );
	if ( ! $o || ! $o->get_meta( '_moksafowo_test' ) ) {
		WP_CLI::error( "#{$id} 不是帶 _moksafowo_test 的測試單。" );
	}
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

$http = static function ( string $body ): array {
	return [ 'headers' => [], 'body' => $body, 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
};

// ---------- 綠界 ----------
$eo = wc_get_order( $ecpay_id );
$eo->set_payment_method( 'moksafowo_ecpay_credit' );
$eo->update_meta_data( Keys::ECPAY_MERCHANT_TRADE_NO, 'MOKSATEST' . $ecpay_id );
$eo->update_meta_data( Keys::ECPAY_TRADE_NO, '2611190000000001' );
$eo->save();
$ecpay_gw = WC()->payment_gateways()->payment_gateways()['moksafowo_ecpay_credit'];

$ecpay_run = static function ( string $status, int $auth, string $amount, array $replies ) use ( $ecpay_id, $ecpay_gw, $http ): array {
	$calls = [];
	$hook  = static function ( $pre, $parsed, $url ) use ( $status, $auth, &$replies, &$calls, $http ) {
		if ( false !== strpos( (string) $url, 'QueryTrade' ) ) {
			$inner = rawurlencode( wp_json_encode( [ 'RtnCode' => 1, 'RtnValue' => [ 'Status' => $status, 'Amount' => $auth, 'ClsAmt' => $auth ] ] ) );
			$data  = openssl_encrypt( $inner, 'aes-128-cbc', EcpayHelper::hash_key(), 0, EcpayHelper::hash_iv() );
			return $http( wp_json_encode( [ 'TransCode' => 1, 'Data' => $data ] ) );
		}
		if ( false !== strpos( (string) $url, 'DoAction' ) ) {
			$calls[] = ( $parsed['body']['Action'] ?? '?' ) . ':' . ( $parsed['body']['TotalAmount'] ?? '' );
			$reply   = array_shift( $replies ) ?? 'ok';
			return $http( 'ok' === $reply ? 'RtnCode=1&RtnMsg=OK' : 'RtnCode=10200047&RtnMsg=fail' );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $hook, 10, 3 );
	$result = $ecpay_gw->process_refund( $ecpay_id, $amount, 'integration test' );
	remove_filter( 'pre_http_request', $hook, 10 );
	return [ $result, $calls ];
};

$ecpay_cases = [
	[ '綠界 已授權 全額 → N', 'Authorized', 300, '300', [], [ 'N:300' ], true ],
	[ '綠界 已授權 部分 → 拒絕', 'Authorized', 300, '100', [], [], false ],
	[ '綠界 要關帳 全額 → E＋N', 'To be captured', 300, '300', [], [ 'E:300', 'N:300' ], true ],
	[ '綠界 要關帳 部分 → R', 'To be captured', 300, '100', [], [ 'R:100' ], true ],
	[ '綠界 已關帳 → R', 'Captured', 300, '100', [], [ 'R:100' ], true ],
	[ '綠界 要關帳 E 成功 N 失敗 → 重送 C', 'To be captured', 300, '300', [ 'ok', 'fail', 'ok' ], [ 'E:300', 'N:300', 'C:300' ], false ],
	[ '綠界 要關帳 E 失敗 → 停下', 'To be captured', 300, '300', [ 'fail' ], [ 'E:300' ], false ],
];
foreach ( $ecpay_cases as [ $label, $status, $auth, $amount, $replies, $want, $want_ok ] ) {
	[ $result, $calls ] = $ecpay_run( $status, $auth, $amount, $replies );
	$pass = $calls === $want && ( true === $result ) === $want_ok;
	$ok( $pass, $label . ( $pass ? '' : ' → [' . implode( ', ', $calls ) . '] ' . ( is_wp_error( $result ) ? $result->get_error_message() : var_export( $result, true ) ) ) );
}

// ---------- 藍新 ----------
$no = wc_get_order( $neweb_id );
$no->set_payment_method( 'moksafowo_newebpay_credit' );
$no->update_meta_data( Keys::NEWEBPAY_MERCHANT_ORDER_NO, 'MOKSATEST' . $neweb_id );
$no->update_meta_data( Keys::NEWEBPAY_PAYMENT_TYPE, 'CREDIT' );
$no->save();
$neweb_gw = WC()->payment_gateways()->payment_gateways()['moksafowo_newebpay_credit'];

$neweb_run = static function ( array $q, string $amount, array $replies, ?int $wc_left = null ) use ( $neweb_id, $neweb_gw, $http ): array {
	// WC 端該請款的金額＝總額−已退（見 payuni-refund-flow.php 同段說明）。
	$o    = wc_get_order( $neweb_id );
	$base = (int) ( $q['CloseAmt'] ?? 0 ) > 0 ? (int) $q['CloseAmt'] : (int) $q['Amt'];
	$o->set_total( (string) ( (float) $o->get_total_refunded() + ( $wc_left ?? max( 0, $base - (int) $amount ) ) ) );
	$o->save();
	$calls = [];
	$hook  = static function ( $pre, $parsed, $url ) use ( $q, &$replies, &$calls, $http ) {
		if ( false !== strpos( (string) $url, 'QueryTradeInfo' ) ) {
			return $http( wp_json_encode( [ 'Status' => 'SUCCESS', 'Message' => 'ok', 'Result' => $q + [ 'TradeStatus' => '1' ] ] ) );
		}
		if ( preg_match( '#/API/CreditCard/(Close|Cancel)$#', (string) $url, $m ) ) {
			$sent    = (array) NewebHelper::decrypt_trade_info( (string) ( $parsed['body']['PostData_'] ?? '' ) );
			$calls[] = $m[1] . ':' . ( $sent['CloseType'] ?? '' ) . ( isset( $sent['Cancel'] ) ? 'x' : '' ) . ':' . ( $sent['Amt'] ?? '' );
			$reply   = array_shift( $replies ) ?? 'ok';
			return $http( wp_json_encode( [ 'Status' => 'ok' === $reply ? 'SUCCESS' : 'TRA10021', 'Message' => $reply, 'Result' => [] ] ) );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $hook, 10, 3 );
	$result = $neweb_gw->process_refund( $neweb_id, $amount, 'integration test' );
	remove_filter( 'pre_http_request', $hook, 10 );
	return [ $result, $calls ];
};

$base        = [ 'Amt' => '300', 'CloseAmt' => '0', 'Inst' => '0' ];
$neweb_cases = [
	[ '藍新 未請款 全額 → 取消授權', [ 'CloseStatus' => '0' ] + $base, '300', [], [ 'Cancel::300' ], true ],
	[ '藍新 未請款 部分 → 請款 200', [ 'CloseStatus' => '0' ] + $base, '100', [], [ 'Close:1:200' ], true ],
	[ '藍新 未請款 分期 部分 → 拒絕', [ 'CloseStatus' => '0', 'Inst' => '3' ] + $base, '100', [], [], false ],
	[ '藍新 等待提送 全額 → 取消請款＋取消授權', [ 'CloseStatus' => '1', 'CloseAmt' => '300' ] + $base, '300', [], [ 'Close:1x:300', 'Cancel::300' ], true ],
	[ '藍新 等待提送 部分 → 取消請款＋請款 200', [ 'CloseStatus' => '1', 'CloseAmt' => '300' ] + $base, '100', [], [ 'Close:1x:300', 'Close:1:200' ], true ],
	[ '藍新 等待提送 部分、重請失敗 → 送回原請款', [ 'CloseStatus' => '1', 'CloseAmt' => '300' ] + $base, '100', [ 'ok', 'fail', 'ok' ], [ 'Close:1x:300', 'Close:1:200', 'Close:1:300' ], false ],
	[ '藍新 請款處理中 → 拒絕', [ 'CloseStatus' => '2' ] + $base, '100', [], [], false ],
	[ '藍新 請款完成 → 退款', [ 'CloseStatus' => '3', 'CloseAmt' => '300' ] + $base, '100', [], [ 'Close:2:100' ], true ],
	[ '藍新 超過可退 → 拒絕', [ 'CloseStatus' => '0' ] + $base, '400', [], [], false ],
	[ '藍新 剩餘跟 WC 帳對不上 → 停下', [ 'CloseStatus' => '0', 'Amt' => '1000' ] + $base, '100', [], [], false, 600 ],
];
foreach ( $neweb_cases as $case ) {
	[ $label, $q, $amount, $replies, $want, $want_ok ] = $case;
	[ $result, $calls ] = $neweb_run( $q, $amount, $replies, $case[6] ?? null );
	$pass = $calls === $want && ( true === $result ) === $want_ok;
	$ok( $pass, $label . ( $pass ? '' : ' → [' . implode( ', ', $calls ) . '] ' . ( is_wp_error( $result ) ? $result->get_error_message() : var_export( $result, true ) ) ) );
}

0 === $fail ? WP_CLI::success( '全部通過' ) : WP_CLI::error( "{$fail} 項未通過" );
