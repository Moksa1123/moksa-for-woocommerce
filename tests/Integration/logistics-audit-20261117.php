<?php
/**
 * 整合測試（測試站 wp eval-file - [訂單ID]）：taiwan-toolkit 11-17 物流清單核對後的四項修正。
 *
 * 1. 綠界物流查詢改 V5：用一筆沙箱物流單實際查，要拿得到 LogisticsStatus。
 * 2. 綠界貨態 4444（訂單建立失敗）：要留「請重新建立物流單」備註（測試單 _moksafowo_test）。
 * 3. 綠界 OK 超商店到店（2026-07-01 終止）：結帳不提供。
 * 4. 藍新物流通知送沒底線的 EncryptData／HashData：要通過驗章（不再回 MissingPayload）。
 *
 * 參數：一張帶 _moksafowo_test=yes 的測試訂單 ID（第 2 項寫備註用）。只打綠界沙箱查詢，不建單。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\EcpayShipping\Api\Helper as EcpayLgs;
use Moksafowo\Modules\NewebpayShipping\Api\Helper as NewebLgs;
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

// 1. V5 查詢：找最近一筆有綠界物流單的訂單。
$query_done = false;
foreach ( wc_get_orders( array( 'limit' => 300, 'orderby' => 'date', 'order' => 'DESC' ) ) as $o ) {
	$recs = $o->get_meta( Keys::ECPAY_LOGISTIC_RECORDS );
	$r    = is_array( $recs ) && $recs ? $recs[0] : array();
	// 只挑真的綠界物流單（純數字）；其他整合測試寫的假紀錄（T 開頭）會被綠界拒絕。
	if ( ! ctype_digit( (string) ( $r['id'] ?? '' ) ) ) {
		continue;
	}
	$res = EcpayLgs::query_logistics_trade_info( (string) $r['id'], (string) ( $r['subtype'] ?? '' ) );
	$ok( false !== strpos( EcpayLgs::query_endpoint(), '/V5' ) && ! empty( $res['ok'] ) && '' !== $res['code'], '綠界 V5 查詢 #' . $o->get_id() . ' → 貨態 ' . ( $res['code'] ?? '' ) . ' ' . ( $res['msg'] ?? '' ) );
	$query_done = true;
	break;
}
if ( ! $query_done ) {
	WP_CLI::warning( '找不到綠界物流單，略過第 1 項' );
}

// 2. 4444 備註。
$order = wc_get_order( absint( $args[0] ?? 0 ) );
if ( $order && 'yes' === $order->get_meta( '_moksafowo_test' ) ) {
	$before = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	do_action( 'moksafowo_ecpay_shipping_status_received', $order, '4444', '訂單建立失敗' );
	$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
	$ok( count( $notes ) > $before && false !== strpos( $notes[0]->content, '4444' ), '4444 留備註：' . wp_strip_all_tags( $notes[0]->content ) );
} else {
	WP_CLI::warning( '沒給測試訂單，略過第 2 項' );
}

// 3. OK 超商不提供。
$okmart = new \Moksafowo\Modules\EcpayShipping\Methods\CvsOkmart();
$ok( false === $okmart->is_available( array( 'contents' => array(), 'destination' => array( 'country' => 'TW' ) ) ), 'OK 超商店到店結帳不提供' );

// 4. 藍新沒底線欄位：不帶貨態碼的內容，驗章通過後應回 1|Ignored。
$plain  = wp_json_encode( array( 'MerchantID' => NewebLgs::merchant_id(), 'MerchantOrderNo' => 'TEST' . time() ) );
$cipher = bin2hex( (string) openssl_encrypt( $plain, 'aes-256-cbc', NewebLgs::hash_key(), OPENSSL_RAW_DATA, NewebLgs::hash_iv() ) );
$hash   = strtoupper( hash( 'sha256', 'HashKey=' . NewebLgs::hash_key() . '&' . $cipher . '&HashIV=' . NewebLgs::hash_iv() ) );
$resp   = wp_remote_post(
	home_url( '/?wc-api=moksafowo_newebpay_shipping_status' ),
	array(
		'timeout'   => 30,
		'sslverify' => false,
		'body'      => array(
			'Status'      => 'SUCCESS',
			'EncryptData' => $cipher,
			'HashData'    => $hash,
		),
	)
);
$body   = is_wp_error( $resp ) ? 'ERR' : trim( (string) wp_remote_retrieve_body( $resp ) );
$ok( '1|Ignored' === $body, '藍新沒底線欄位通過驗章：' . $body );

$fail ? WP_CLI::error( "{$fail} 項未通過" ) : WP_CLI::success( '全部通過' );
