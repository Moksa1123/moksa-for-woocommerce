<?php
/**
 * 整合測試（測試站 wp eval-file -）：統一金流退貨紀錄、反查、貨態通知。
 *
 * 1. 加一筆 7-11 退貨便紀錄後，用 12 碼反查得到訂單；只對 8 碼或驗證碼錯的反查不到。
 * 2. 退貨便貨態通知：更新退貨紀錄、多一則備註、觸發 moksafowo_payuni_return_status；出貨貨態與訂單狀態不變。
 * 3. 同一則通知重送：不重複寫備註。
 * 4. 黑貓退貨紀錄：用退貨單的物流序號反查，通知帶 OBTNumber 會補進紀錄。
 * 5. 沙箱建立退貨便：沒開通的商店回好懂的錯誤（DEF05004），訂單不留東西。
 *
 * 訂單帶 _moksafowo_test=yes，只存在測試站。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Returns\Api;
use Moksafowo\Modules\PayuniShipping\Returns\Notice;
use Moksafowo\Modules\PayuniShipping\Returns\Store;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;

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
$order->update_meta_data( '_moksafowo_test', 'yes' );
$order->update_meta_data( OrderMeta::ShipStatus, '11' );
$order->save();
$order->update_status( 'completed' );
$order_id = $order->get_id();
$notes    = static function () use ( $order_id ): int {
	return count( wc_get_order_notes( array( 'order_id' => $order_id ) ) );
};

// 1. 反查。
$suffix = substr( (string) time(), -7 );
$c2b    = 'Z' . $suffix . '1234';
$order  = wc_get_order( $order_id );
Store::add(
	$order,
	array(
		'type'     => Store::TYPE_C2B,
		'ref'      => $c2b,
		'deadline' => '2099-01-01 23:59:59',
	)
);
$order->save();
$found = Store::find_order( $c2b );
$ok( $found instanceof WC_Order && $found->get_id() === $order_id, '12 碼反查得到訂單' );
$ok( null === Store::find_order( 'Z' . $suffix ), '只有 8 碼反查不到' );
$ok( null === Store::find_order( 'Z' . $suffix . '9999' ), '驗證碼錯反查不到' );

// 2. 退貨便貨態通知。
$fired = 0;
add_action(
	'moksafowo_payuni_return_status',
	static function () use ( &$fired ): void {
		++$fired;
	}
);
$before = $notes();
$notice = array(
	'Status'         => 'SUCCESS',
	'ApiType'        => 'ShipStatus',
	'LgsType'        => 'C2B',
	'RefundODNO'     => 'Z' . $suffix,
	'ValidationNo'   => '1234',
	'ShipStatus'     => '51',
	'ShipStatusDesc' => 'TEST return',
	'ShipStatusTime' => '2026-11-25 12:00:00',
);
\Moksafowo\Modules\PayuniShipping\Api\ShippingResponse::handle_711( $notice );
wp_cache_flush();
$o      = wc_get_order( $order_id );
$record = Store::find( $o, $c2b );
$ok( '51' === (string) ( $record['status'] ?? '' ), '退貨紀錄貨態更新成 51' );
$ok( $notes() === $before + 1 && 1 === $fired, '多一則備註、觸發 moksafowo_payuni_return_status' );
$ok( '11' === (string) $o->get_meta( OrderMeta::ShipStatus ) && 'completed' === $o->get_status(), '出貨貨態與訂單狀態不變' );

// 3. 重送。
\Moksafowo\Modules\PayuniShipping\Api\ShippingResponse::handle_711( $notice );
wp_cache_flush();
$ok( $notes() === $before + 1 && 1 === $fired, '同一則通知重送不重複備註' );

// 4. 黑貓退貨。
$home  = 'TESTHOME' . time();
$order = wc_get_order( $order_id );
Store::add(
	$order,
	array(
		'type'      => Store::TYPE_HOME,
		'ref'       => $home,
		'ship_date' => '2099-01-01',
	)
);
$order->save();
\Moksafowo\Modules\PayuniShipping\Api\ShippingResponse::handle_tcat(
	array(
		'Status'         => 'SUCCESS',
		'ApiType'        => 'ShipStatus',
		'LgsType'        => 'HOME',
		'ShipType'       => '2',
		'TradeType'      => '2',
		'ShipTradeNo'    => $home,
		'OBTNumber'      => '888888888888',
		'ShipStatus'     => '31',
		'ShipStatusDesc' => 'TEST pickup',
		'ShipStatusTime' => '2026-11-25 13:00:00',
	)
);
wp_cache_flush();
$o      = wc_get_order( $order_id );
$record = Store::find( $o, $home );
$ok( '31' === (string) ( $record['status'] ?? '' ) && '888888888888' === (string) ( $record['obt_number'] ?? '' ), '黑貓退貨紀錄更新貨態與退貨編號' );
$ok( '11' === (string) $o->get_meta( OrderMeta::ShipStatus ), '黑貓退貨不動出貨貨態' );

// 4b. 建立退貨的鎖：同一張訂單第二次拿不到，放掉後可以再拿；過期的鎖會被清掉。
$acquire = new ReflectionMethod( \Moksafowo\Modules\PayuniShipping\Returns\Admin::class, 'acquire_lock' );
$release = new ReflectionMethod( \Moksafowo\Modules\PayuniShipping\Returns\Admin::class, 'release_lock' );
$acquire->setAccessible( true );
$release->setAccessible( true );
$first  = $acquire->invoke( null, $order_id );
$second = $acquire->invoke( null, $order_id );
$release->invoke( null, $order_id );
$third = $acquire->invoke( null, $order_id );
update_option( 'moksafowo_payuni_return_lock_' . $order_id . '.lock', time() - 600, false );
$stale = $acquire->invoke( null, $order_id );
$release->invoke( null, $order_id );
$ok( true === $first && false === $second && true === $third && true === $stale, '建立鎖：重複拿不到、放掉可再拿、過期會清掉' );
$ok( ! in_array( 'moksafowo_payuni_return_lock_' . $order_id . '.lock', \Moksafowo\Settings\OptionRegistry::names(), true ), '鎖不會記進解除安裝清單' );

// 5. 沙箱建立退貨便。
if ( PayuniShipping::$testmode ) {
	$before = $notes();
	$resp   = Api::create_c2b(
		array(
			'service_type'  => 5,
			'trade_amt'     => 1,
			'ship_amt'      => 0,
			'shopper_name'  => 'TEST',
			'ship_trade_no' => '',
		)
	);
	$ok( is_wp_error( $resp ) && false !== strpos( $resp->get_error_message(), 'DEF05004' ), '沙箱未開通：回錯誤並帶 DEF05004（' . ( is_wp_error( $resp ) ? $resp->get_error_message() : 'no error' ) . '）' );
	$ok( $notes() === $before && 2 === count( Store::all( wc_get_order( $order_id ) ) ), '建立失敗不留紀錄' );
} else {
	WP_CLI::log( 'SKIP 不是測試模式，不打建立 API' );
}

WP_CLI::log( "測試單 #{$order_id}（帶 _moksafowo_test）" );
if ( $fail > 0 ) {
	WP_CLI::error( "{$fail} 項失敗" );
}
WP_CLI::success( '全部通過' );
