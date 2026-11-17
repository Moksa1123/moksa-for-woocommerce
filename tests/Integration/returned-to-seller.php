<?php
/**
 * 整合測試（測試站 wp eval-file - <商品ID>）：包裹回到賣家手上（綠界 2070 等）的訂單處理。
 *
 * 1. 取貨付款（cod）、單一包裹 → 已取消，庫存加回。
 * 2. 已付款（綠界信用卡）→ 未取件＋一則「請決定退款或重寄」備註；同一通知重送不重複備註。
 * 3. 取貨付款、拆兩包、只有一包退回 → 不取消。
 *
 * 每張單都帶 _moksafowo_test=yes，只存在測試站。商品要有開庫存管理（第 1 項看庫存）。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Order\Meta\Keys;

$product_id = absint( $args[0] ?? 0 );
$product    = wc_get_product( $product_id );
if ( ! $product || ! $product->managing_stock() ) {
	WP_CLI::error( '請給一個有開庫存管理的測試商品 ID。' );
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

$make = static function ( string $gateway, array $codes ) use ( $product ): \WC_Order {
	$order = wc_create_order();
	$order->add_product( $product, 1 );
	$order->set_payment_method( $gateway );
	$order->calculate_totals( false );
	$order->update_meta_data( '_moksafowo_test', 'yes' );
	$records = array();
	foreach ( $codes as $i => $code ) {
		$records[] = array(
			'id'       => 'T' . time() . $i,
			'subtype'  => 'UNIMARTC2C',
			'rtn_code' => $code,
		);
	}
	$order->update_meta_data( Keys::ECPAY_LOGISTIC_RECORDS, $records );
	$order->save();
	$order->update_status( 'processing' ); // 會扣庫存。
	wc_reduce_stock_levels( $order->get_id() );
	return wc_get_order( $order->get_id() );
};
$fire = static function ( \WC_Order $order, string $code ): \WC_Order {
	do_action( 'moksafowo_ecpay_shipping_status_received', $order, $code, '賣家已取退回包裹' );
	wp_cache_flush();
	return wc_get_order( $order->get_id() );
};

// 1. 取貨付款、單一包裹。
$stock_before = (int) wc_get_product( $product->get_id() )->get_stock_quantity();
$o            = $make( 'cod', array( '2070' ) );
$stock_mid    = (int) wc_get_product( $product->get_id() )->get_stock_quantity();
$o            = $fire( $o, '2070' );
$stock_after  = (int) wc_get_product( $product->get_id() )->get_stock_quantity();
$ok( 'cancelled' === $o->get_status(), "取貨付款 #{$o->get_id()} → {$o->get_status()}" );
$ok( $stock_mid === $stock_before - 1 && $stock_after === $stock_before, "庫存 {$stock_before} → 下單 {$stock_mid} → 取消後 {$stock_after}" );

// 2. 已付款。
$o = $make( 'moksafowo_ecpay_credit', array( '2070' ) );
$o = $fire( $o, '2070' );
$o = $fire( $o, '2070' );
$n = 0;
foreach ( wc_get_order_notes( array( 'order_id' => $o->get_id() ) ) as $note ) {
	if ( false !== strpos( $note->content, 'back with the seller' ) || false !== strpos( $note->content, '回到賣家' ) ) {
		++$n;
	}
}
$ok( 'moksa-unclaimed' === $o->get_status() && 1 === $n, "已付款 #{$o->get_id()} → {$o->get_status()}，提醒備註 {$n} 則" );
$o->update_status( 'cancelled', 'integration test cleanup' ); // 把扣掉的庫存加回。

// 3. 取貨付款、拆兩包、一包已取貨。
$o = $make( 'cod', array( '2067', '2070' ) );
$o = $fire( $o, '2070' );
$ok( 'cancelled' !== $o->get_status(), "拆兩包只退一包 #{$o->get_id()} → {$o->get_status()}（不取消）" );
$o->update_status( 'cancelled', 'integration test cleanup' );

$fail ? WP_CLI::error( "{$fail} 項未通過" ) : WP_CLI::success( '全部通過' );
