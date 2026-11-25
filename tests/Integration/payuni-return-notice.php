<?php
/**
 * 整合測試（測試站 wp eval-file -）：統一金流退貨的貨態通知不能被當成出貨貨態。
 *
 * 1. 黑貓逆物流（TradeType=2）沿用原單物流序號：出貨貨態、訂單狀態都不變，多一則退貨貨態備註。
 * 2. 黑貓正物流（TradeType=1）：照常更新出貨貨態（對照組）。
 * 3. 7-11 退貨便（RefundODNO，沒有物流序號）：不改任何訂單。
 *
 * 訂單帶 _moksafowo_test=yes，只存在測試站。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\PayuniShipping\Api\ShippingResponse;
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

$ship_trade_no = 'TESTRTN' . time();
$order         = wc_create_order();
$order->update_meta_data( '_moksafowo_test', 'yes' );
$order->update_meta_data( OrderMeta::ShipTradeNo, $ship_trade_no );
$order->update_meta_data( OrderMeta::ShipStatus, '31' );
$order->save();
$order->update_status( 'processing' );
$order_id = $order->get_id();

$notes = static function () use ( $order_id ): int {
	return count( wc_get_order_notes( array( 'order_id' => $order_id ) ) );
};

$base = array(
	'Status'         => 'SUCCESS',
	'ApiType'        => 'ShipStatus',
	'ShipTradeNo'    => $ship_trade_no,
	'LgsType'        => 'HOME',
	'ShipType'       => '2',
	'OBTNumber'      => '999999999999',
	'ShipStatus'     => '11',
	'ShipStatusDesc' => 'TEST',
	'ShipStatusTime' => '2026-11-25 12:00:00',
);

// 1. 逆物流。
$before = $notes();
ShippingResponse::handle_tcat( array( 'TradeType' => '2' ) + $base );
wp_cache_flush();
$o = wc_get_order( $order_id );
$ok( '31' === (string) $o->get_meta( OrderMeta::ShipStatus ), '逆物流：出貨貨態維持 31（實際 ' . $o->get_meta( OrderMeta::ShipStatus ) . '）' );
$ok( 'processing' === $o->get_status(), '逆物流：訂單狀態維持 processing（實際 ' . $o->get_status() . '）' );
$ok( $notes() === $before + 1, '逆物流：多一則備註' );

// 1b. 逆物流經付款通知網址轉來（Frontstage 路由）。
$before = $notes();
\Moksafowo\Modules\PayuniShipping\Frontstage::route_logistics_notify( array( 'TradeType' => '2' ) + $base );
wp_cache_flush();
$o = wc_get_order( $order_id );
$ok( '31' === (string) $o->get_meta( OrderMeta::ShipStatus ) && 'processing' === $o->get_status() && $notes() === $before + 1, '逆物流（付款通知網址轉來）：出貨貨態、訂單狀態不變，多一則備註' );

// 3. 7-11 退貨便（沒有 ShipTradeNo）。
$before = $notes();
ShippingResponse::handle_711(
	array(
		'Status'         => 'SUCCESS',
		'ApiType'        => 'ShipStatus',
		'LgsType'        => 'C2B',
		'RefundODNO'     => 'Z0000000',
		'ValidationNo'   => '0000',
		'ShipStatus'     => '11',
		'ShipStatusDesc' => 'TEST',
	)
);
wp_cache_flush();
$o = wc_get_order( $order_id );
$ok( '31' === (string) $o->get_meta( OrderMeta::ShipStatus ) && $notes() === $before, '退貨便：訂單沒有被改動' );

// 2. 正物流（對照組）。
ShippingResponse::handle_tcat( array( 'TradeType' => '1' ) + $base );
wp_cache_flush();
$o = wc_get_order( $order_id );
$ok( '11' === (string) $o->get_meta( OrderMeta::ShipStatus ), '正物流：出貨貨態更新成 11' );

WP_CLI::log( "測試單 #{$order_id}（帶 _moksafowo_test）" );
if ( $fail > 0 ) {
	WP_CLI::error( "{$fail} 項失敗" );
}
WP_CLI::success( '全部通過' );
