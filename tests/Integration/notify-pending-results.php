<?php
/**
 * 整合測試（測試站 wp eval-file -）：「付款結果未確定」的通知不能當成已付款或失敗。
 *
 * 1. 綠界 RtnCode=10300066（付款結果待確認）→ 保留（on-hold），不是 failed。
 * 2. 藍新先買後付 Status=SUCCESS＋TradeStatus=0（未付款）→ 保留，不是已付款；2 → failed；1 → 照常付款完成（對照組）。
 *
 * 每個情境建一張帶 _moksafowo_test=yes 的測試單（金額 1000 元的手續費，只存在測試站），用外掛自己的沙箱金鑰簽通知，
 * POST 到本站通知網址，再看訂單狀態。不打任何金流。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\Ecpay\Api\Helper as Ecpay;
use Moksafowo\Modules\Newebpay\Api\Helper as Newebpay;

$make_order = static function ( string $gateway ): \WC_Order {
	$order = wc_create_order();
	$order->set_payment_method( $gateway );
	$order->set_billing_email( 'notify-test@example.com' );
	$fee = new \WC_Order_Item_Fee();
	$fee->set_name( 'notify test' );
	$fee->set_total( '1000' );
	$order->add_item( $fee );
	$order->calculate_totals( false );
	$order->update_meta_data( '_moksafowo_test', 'yes' );
	$order->set_status( 'pending' );
	$order->save();
	return $order;
};
$post = static function ( string $api, array $body ): string {
	$r = wp_remote_post( home_url( '/?wc-api=' . $api ), array( 'body' => $body, 'timeout' => 30, 'sslverify' => false ) );
	return is_wp_error( $r ) ? 'ERR ' . $r->get_error_message() : trim( (string) wp_remote_retrieve_body( $r ) );
};
$fail = 0;
$check = static function ( string $label, int $order_id, string $want ) use ( &$fail ): void {
	wp_cache_flush();
	$o   = wc_get_order( $order_id );
	$got = 'paid' === $want && $o->is_paid() ? 'paid' : $o->get_status();
	if ( $got === $want ) {
		WP_CLI::log( "OK  {$label}: #{$order_id} → {$got}" );
	} else {
		++$fail;
		WP_CLI::warning( "NG  {$label}: #{$order_id} → {$got}（應為 {$want}）" );
	}
};

// 1. 綠界 10300066。
$o   = $make_order( 'moksafowo_ecpay_credit' );
$mtn = Ecpay::generate_merchant_trade_no( $o->get_id() );
Ecpay::remember_mtn( $o, $mtn );
$o->save();
$data = array(
	'MerchantID'           => Ecpay::merchant_id(),
	'MerchantTradeNo'      => $mtn,
	'RtnCode'              => '10300066',
	'RtnMsg'               => 'pending',
	'TradeNo'              => 'T' . time(),
	'TradeAmt'             => '1000',
	'PaymentDate'          => '',
	'PaymentType'          => 'Credit_CreditCard',
	'PaymentTypeChargeFee' => '0',
	'TradeDate'            => gmdate( 'Y/m/d H:i:s' ),
	'SimulatePaid'         => '0',
);
$data['CheckMacValue'] = Ecpay::generate_check_mac_value( $data );
WP_CLI::log( 'ECPay 回應：' . $post( 'moksafowo_ecpay_payment', $data ) );
$check( '綠界 10300066', $o->get_id(), 'on-hold' );

// 2. 藍新 AFTEE TradeStatus=0、2、1（1 是對照組，只有手續費的單付款後 WC 會直接完成，所以看 is_paid）。
foreach ( array( '0' => 'on-hold', '2' => 'failed', '1' => 'paid' ) as $trade_status => $want ) {
	$o   = $make_order( 'moksafowo_newebpay_aftee' );
	$mtn = Newebpay::generate_merchant_order_no( $o->get_id() );
	Newebpay::remember_mtn( $o, $mtn );
	$o->save();
	$trade_no = 'N' . wp_rand( 100000, 999999 ) . time();
	$result   = array(
		'MerchantID'      => Newebpay::merchant_id(),
		'Amt'             => 1000,
		'TradeNo'         => $trade_no,
		'MerchantOrderNo' => $mtn,
		'PaymentType'     => 'AFTEE',
		'PayTime'         => gmdate( 'Y-m-d H:i:s' ),
		'TradeStatus'     => $trade_status,
	);
	$result['CheckCode'] = Newebpay::generate_notify_check_code( $result );
	$info = Newebpay::encrypt_trade_info( array( 'Status' => 'SUCCESS', 'Message' => 'test', 'Result' => $result ) );
	WP_CLI::log( '藍新回應：' . $post( 'moksafowo_newebpay_payment', array(
		'Status'     => 'SUCCESS',
		'MerchantID' => Newebpay::merchant_id(),
		'TradeInfo'  => $info,
		'TradeSha'   => Newebpay::generate_trade_sha( $info ),
		'Version'    => '2.0',
	) ) );
	$check( "藍新 AFTEE TradeStatus={$trade_status}", $o->get_id(), $want );
}

$fail ? WP_CLI::error( "{$fail} 項未通過" ) : WP_CLI::success( '全部通過' );
