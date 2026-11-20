<?php
/**
 * 整合測試（測試站 wp eval-file - <測試會員ID> <測試訂單ID>）：從 woomp 換過來的縣市與出貨狀態。
 *
 * - 縣市：woomp（twzipcode）存中文「臺北市」等，Moksa 下拉用代碼。對照表每一筆都要對到 WooCommerce
 *   台灣縣市清單裡的代碼；結帳頁把中文帶成代碼；工具只改指定的測試會員，改完還原。
 * - 狀態：woomp 的 wmp-shipped／wmp-in-transit 沒有 woomp 時也要註冊、算已付款，
 *   批次轉換成 moksa-shipped 而且不寄信。
 *
 * 參數：一位測試會員（user meta 有 _moksafowo_test）、一張帶 _moksafowo_test 的測試訂單。
 * 需要「台灣地址工具」與縣市下拉、Moksa 訂單狀態都開著。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\Address\LegacyStateMap;
use Moksafowo\Modules\Shipping\Statuses\LegacyStatuses;

$user_id  = absint( $args[0] ?? 0 );
$order_id = absint( $args[1] ?? 0 );
$order    = wc_get_order( $order_id );
if ( ! $user_id || ! get_user_meta( $user_id, '_moksafowo_test', true ) || ! $order || ! $order->get_meta( '_moksafowo_test' ) ) {
	WP_CLI::error( '請給一位帶 _moksafowo_test 的測試會員和一張帶 _moksafowo_test 的測試訂單。' );
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

// 1. 對照表每一筆都對到真的代碼，22 個縣市都有。
$states = (array) WC()->countries->get_states( 'TW' );
$map    = LegacyStateMap::map();
$bad    = array_filter( $map, static fn( $code ) => ! isset( $states[ $code ] ) );
$ok( [] === $bad, '對照表 ' . count( $map ) . ' 筆都對到台灣縣市代碼' . ( $bad ? '（錯：' . implode( ',', array_keys( $bad ) ) . '）' : '' ) );
$ok( 22 === count( $states ) && 22 === count( array_unique( $map ) ), '22 個縣市都有中文對照（含金門、連江、澎湖）' );
$ok( 'TAIPEI CITY' === LegacyStateMap::to_code( '臺北市' ) && 'TAIPEI CITY' === LegacyStateMap::to_code( '台北市' ) && 'TAITUNG COUNTY' === LegacyStateMap::to_code( '臺東縣' ), '臺／台兩種寫法都認得' );
$ok( '' === LegacyStateMap::to_code( '南海島' ) && '' === LegacyStateMap::to_code( 'TAIPEI CITY' ), '不認得的、已經是代碼的都不動' );

// 2. 結帳頁帶入。
$ok( 'KINMEN COUNTY' === LegacyStateMap::checkout_value( '金門縣', 'billing_state' ) && '金門縣' === LegacyStateMap::checkout_value( '金門縣', 'billing_city' ), '結帳頁：縣市欄位帶成代碼，其他欄位不動' );
// G4：要掛在 WC 真的會傳值的 default_checkout_{欄位}（woocommerce_checkout_get_value 傳進來固定是 null）。
$ok( 'KINMEN COUNTY' === apply_filters( 'default_checkout_billing_state', '金門縣', 'billing_state' ) && 'PENGHU COUNTY' === apply_filters( 'default_checkout_shipping_state', '澎湖縣', 'shipping_state' ), '結帳頁的實際 filter 有接上' );

// 3. 工具：只改測試會員。
$keep = [];
foreach ( [ 'billing_state', 'billing_country', 'shipping_state', 'shipping_country' ] as $k ) {
	$keep[ $k ] = get_user_meta( $user_id, $k, true );
}
update_user_meta( $user_id, 'billing_country', 'TW' );
update_user_meta( $user_id, 'billing_state', '臺中市' );
update_user_meta( $user_id, 'shipping_country', 'JP' );
update_user_meta( $user_id, 'shipping_state', '臺中市' );
$dry = LegacyStateMap::scan( false, [ $user_id ] );
$ok( 1 === $dry['users'] && 1 === $dry['fields'] && '臺中市' === get_user_meta( $user_id, 'billing_state', true ), '計數：1 位會員 1 個欄位，計數時不改資料（國家不是台灣的不算）' );
$done = LegacyStateMap::scan( true, [ $user_id ] );
$ok( 1 === $done['fields'] && 'TAICHUNG CITY' === get_user_meta( $user_id, 'billing_state', true ) && '臺中市' === get_user_meta( $user_id, 'shipping_state', true ), '執行：帳單縣市改成 TAICHUNG CITY，日本的運送地址不動' );
$again = LegacyStateMap::scan( false, [ $user_id ] );
$ok( 0 === $again['users'], '再計數一次是 0（不重複改）' );
foreach ( $keep as $k => $v ) {
	update_user_meta( $user_id, $k, $v );
}

// 4. woomp 狀態。
$list = wc_get_order_statuses();
$ok( isset( $list['wc-wmp-shipped'], $list['wc-wmp-in-transit'] ), '訂單狀態清單有 wmp-shipped、wmp-in-transit（' . ( $list['wc-wmp-shipped'] ?? '-' ) . '／' . ( $list['wc-wmp-in-transit'] ?? '-' ) . '）' );
$paid = wc_get_is_paid_statuses();
$ok( in_array( 'wmp-shipped', $paid, true ) && in_array( 'wmp-in-transit', $paid, true ), '兩個都算已付款' );
$ok( 'moksa-shipped' === ( LegacyStatuses::conversions()['wmp-in-transit'] ?? '' ), '配送中也轉成已出貨' );

$orig  = $order->get_status();
$mails = 0;
add_filter(
	'pre_wp_mail',
	static function () use ( &$mails ) {
		++$mails;
		return false;
	},
	1
);
foreach ( [ 'wmp-shipped', 'wmp-in-transit' ] as $legacy ) {
	$o = wc_get_order( $order_id );
	$o->set_status( $legacy );
	$o->save();
	$before = LegacyStatuses::count();
	$mails  = 0;
	$admins = get_users(
		[
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		]
	);
	wp_set_current_user( (int) $admins[0] );
	LegacyStatuses::handle_bulk_action( admin_url(), 'moksafowo_convert_ry_status', [ $order_id ] );
	wp_set_current_user( 0 );
	$now = wc_get_order( $order_id )->get_status();
	$ok( 'moksa-shipped' === $now && 0 === $mails && LegacyStatuses::count() === $before - 1, "{$legacy} 批次轉換 → {$now}，寄信 {$mails} 封，計數 {$before} → " . LegacyStatuses::count() );
}
$o = wc_get_order( $order_id );
$o->set_status( $orig );
$o->save();

0 === $fail ? WP_CLI::success( '全部通過' ) : WP_CLI::error( "{$fail} 項未通過" );
