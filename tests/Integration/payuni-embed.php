<?php
/**
 * 整合測試（測試站 wp eval-file -）：統一金流免跳轉信用卡的伺服器端。
 *
 * 1. 只在「分開顯示」時啟用；合併顯示一律跳轉。
 * 2. 沙箱拿 SDK token：一般、約定信用卡兩種都成功。
 * 3. 沒有 session token 時下單：回錯誤、不打授權。
 * 4. 前端結果驗章：HashInfo 不對、token 不是這個 session 的，都不算綁卡成功。
 * 5. 3D 導頁網址只接受統一金流網域。
 *
 * 實際刷卡（SDK 填卡、3D、續扣）用 Playwright 在測試站結帳頁跑，見 docs/security 紀錄。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\Payuni\Credentials;
use Moksafowo\Modules\Payuni\Embed\Embed;
use Moksafowo\Modules\Payuni\Embed\Trade;
use Moksafowo\Modules\Payuni\PayuniPayment;

$fail = 0;
$ok   = static function ( bool $pass, string $label ) use ( &$fail ): void {
	if ( $pass ) {
		WP_CLI::log( 'OK  ' . $label );
	} else {
		++$fail;
		WP_CLI::warning( 'NG  ' . $label );
	}
};

if ( ! Credentials::test_mode_enabled() ) {
	WP_CLI::error( '金流不是測試模式，不跑' );
}

// 1. 顯示方式。
$mode  = get_option( 'moksafowo_payuni_display_mode', 'multi' );
$embed = get_option( Embed::OPTION, 'no' );
update_option( Embed::OPTION, 'yes' );
update_option( 'moksafowo_payuni_display_mode', 'single' );
$single = Embed::enabled();
update_option( 'moksafowo_payuni_display_mode', 'multi' );
$multi = Embed::enabled();
update_option( 'moksafowo_payuni_display_mode', $mode );
update_option( Embed::OPTION, $embed );
$ok( ! $single && $multi, '合併顯示不啟用、分開顯示啟用' );

// 2. token。
$plain = Embed::token_get();
$agree = Embed::token_get( 'mowptest' . bin2hex( random_bytes( 6 ) ) );
$ok( $plain['ok'] && 64 === strlen( $plain['token'] ) && $plain['expires'] > time(), '一般 token：成功、64 碼、有到期時間' );
$ok( $agree['ok'] && $agree['token'] !== $plain['token'], '約定信用卡 token：成功，跟一般的不同' );

// 3. 沒有 session token。
WC()->session = new WC_Session_Handler();
WC()->session->init();
wc_clear_notices();
$order = wc_create_order();
$order->update_meta_data( '_moksafowo_test', 'yes' );
$order->set_payment_method( 'moksafowo_payuni_credit' );
$order->set_total( 10 );
$order->save();
$gateway = new \Moksafowo\Modules\Payuni\Gateways\Credit();
$result  = Trade::process( $order, $gateway, 'x', 'y' );
$notices = wc_get_notices( 'error' );
$ok( 'failure' === $result['result'] && ! empty( $notices ) && '' === (string) wc_get_order( $order->get_id() )->get_meta( '_moksafowo_payuni_order_no' ), '沒有 token：回錯誤，沒有送出授權' );
wc_clear_notices();

// 4. 前端結果驗章。
$bind = new ReflectionMethod( Trade::class, 'bind_ok' );
$bind->setAccessible( true );
$good = PayuniPayment::encrypt(
	array(
		'Status'  => 'SUCCESS',
		'Message' => 'test',
		'Token'   => $plain['token'],
	)
);
$ok( true === $bind->invoke( null, $good, PayuniPayment::hash_info( $good ), $plain['token'] ), '驗章對、token 對：算綁卡成功' );
$ok( false === $bind->invoke( null, $good, str_repeat( 'A', 64 ), $plain['token'] ), 'HashInfo 不對：不算' );
$ok( false === $bind->invoke( null, $good, PayuniPayment::hash_info( $good ), $agree['token'] ), 'token 不是這個 session 的：不算' );

// 5. 3D 網址。
$url = new ReflectionMethod( Trade::class, 'is_payuni_url' );
$url->setAccessible( true );
$ok(
	$url->invoke( null, 'https://sandbox-api.payuni.com.tw/api/credit/api_3d/1' )
	&& ! $url->invoke( null, 'http://api.payuni.com.tw/x' )
	&& ! $url->invoke( null, 'javascript:alert(1)' ),
	'3D 網址只接受 https'
);

// 6. 結果不明時，已經被通知入帳的訂單不能改回保留中。
$paid = wc_create_order();
$paid->update_meta_data( '_moksafowo_test', 'yes' );
$paid->set_payment_method( 'moksafowo_payuni_credit' );
$paid->set_total( 10 );
$paid->save();
$paid->payment_complete( 'TEST' );
$unknown = new ReflectionMethod( Trade::class, 'unknown' );
$unknown->setAccessible( true );
$stale = wc_get_order( $paid->get_id() );
$stale->set_status( 'pending' ); // 模擬授權前讀到的舊物件。
$res = $unknown->invoke( null, $stale, 'test' );
$after = wc_get_order( $paid->get_id() );
$ok( 'success' === $res['result'] && $after->is_paid() && ! $after->has_status( 'on-hold' ), '結果不明：已付款訂單不改回保留中（' . $after->get_status() . '）' );

WP_CLI::log( "測試單 #{$order->get_id()}（帶 _moksafowo_test）" );
if ( $fail > 0 ) {
	WP_CLI::error( "{$fail} 項失敗" );
}
WP_CLI::success( '全部通過' );
