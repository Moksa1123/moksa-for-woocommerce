<?php
/**
 * 整合測試（測試站 wp eval-file - <訂單ID>）：從 woomp 換過來的商店，舊訂單上的綠界發票。
 *
 * 用綠界發票沙箱真的開一張票，再把號碼搬到 woomp 的欄位（_ecpay_invoice_number／_ecpay_invoice_status
 * ＋woomp 格式的備註），模擬換外掛前開的發票，檢查：
 * - 自動開立、後台開立都會擋（不重複開票）；ERP 寫的「ERP開立」佔位值也擋，但不能作廢折讓。
 * - 開立日期：從備註取；沒備註時用 B2CInvoice/GetIssueList 找。
 * - 折讓 → 有折讓時不能作廢 → 作廢折讓 → 作廢發票。
 * - 退款時發票動作列「手動處理」，不會當成還沒開。
 *
 * 參數：一張帶 _moksafowo_test、金額 ≥ 10、有 email 的已付款測試訂單。會真的在綠界沙箱開票再作廢。
 *
 * 官方依據（developers.ecpay.com.tw）：
 * - B2CInvoice/Issue、Invalid、Allowance、AllowanceInvalid：作廢與折讓都要 InvoiceNo＋InvoiceDate
 *   （GetIssue 日期錯或空白回「查無發票資料」，2026-11-20 沙箱實測）。
 * - /17229 查詢特定多筆發票 GetIssueList：BeginDate／EndDate、NumPerPage ≤ 200、ShowingPage，回 InvoiceData[].IIS_Number。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\EcpayInvoice\Operations as Op;
use Moksafowo\Modules\Shared\Compat\LegacyOrderData;
use Moksafowo\Order\Meta\Keys;

$order_id = absint( $args[0] ?? 0 );
$order    = wc_get_order( $order_id );
if ( ! $order || ! $order->get_meta( '_moksafowo_test' ) || $order->get_total() < 10 || '' === $order->get_billing_email() ) {
	WP_CLI::error( '請給一張帶 _moksafowo_test、金額 ≥ 10、有 email 的測試訂單。' );
}

$fail  = 0;
$ok    = static function ( bool $pass, string $label ) use ( &$fail ): void {
	if ( $pass ) {
		WP_CLI::log( 'OK  ' . $label );
	} else {
		++$fail;
		WP_CLI::warning( 'NG  ' . $label );
	}
};
$clean = static function () use ( $order_id ): void {
	$o = wc_get_order( $order_id );
	foreach ( [ '_ecpay_invoice_number', '_ecpay_invoice_status', '_ecpay_invoice_data', Keys::LEGACY_INVOICE_DATE, Keys::LEGACY_INVOICE_ALLOWANCES, Keys::LEGACY_INVOICE_VOIDED_AT, Keys::LEGACY_INVOICE_VOID_REASON, Keys::ECPAY_INVOICE_NUMBER, Keys::ECPAY_INVOICE_ISSUED_AT, Keys::ECPAY_INVOICE_INVALID_AT, Keys::ECPAY_INVOICE_RELATE_NUMBER, Keys::ECPAY_INVOICE_RANDOM ] as $k ) {
		$o->delete_meta_data( $k );
	}
	$o->save();
};
$clean();

// 1. 用 Moksa 在沙箱開一張，搬成 woomp 的樣子。
$order->update_meta_data( Keys::INVOICE_TYPE, 'b2c_carrier' );
$order->update_meta_data( Keys::INVOICE_CARRIER_TYPE, '' );
$order->update_meta_data( Keys::INVOICE_PROVIDER, 'ecpay' );
$order->save();
$issued = Op\Issue::run( $order );
$order  = wc_get_order( $order_id );
$no     = (string) $order->get_meta( Keys::ECPAY_INVOICE_NUMBER );
$at     = (string) $order->get_meta( Keys::ECPAY_INVOICE_ISSUED_AT );
$ok( ! empty( $issued['ok'] ) && '' !== $no, "沙箱開票 {$no} {$at}" );
if ( '' === $no ) {
	WP_CLI::error( '沙箱開票失敗：' . ( $issued['message'] ?? '' ) );
}
foreach ( [ Keys::ECPAY_INVOICE_NUMBER, Keys::ECPAY_INVOICE_ISSUED_AT, Keys::ECPAY_INVOICE_RELATE_NUMBER, Keys::ECPAY_INVOICE_RANDOM ] as $k ) {
	$order->delete_meta_data( $k );
}
$order->update_meta_data( '_ecpay_invoice_number', $no );
$order->update_meta_data( '_ecpay_invoice_status', 1 );
$order->update_meta_data(
	'_ecpay_invoice_data',
	[
		'_invoice_type'       => 'individual',
		'_invoice_individual' => '手機條碼',
		'_invoice_carrier'    => '/ABC+123',
	]
);
$order->save();
$order->add_order_note( '<b>發票開立結果</b><br>產生時間: ' . $at . '<br>發票號碼: ' . $no . '<br>回傳訊息: 開立發票成功' );
$order = wc_get_order( $order_id );

// 2. 認得、擋重複開票。
$legacy = LegacyOrderData::invoice( $order );
$ok( null !== $legacy && 'woomp' === $legacy['source_key'] && $no === $legacy['number'], 'woomp 發票認得出來' );
$ok( LegacyOrderData::ecpay_actionable( $legacy ), '可以由 Moksa 作廢折讓' );
$ok( true === LegacyOrderData::skip_issue_if_invoiced( $order ), '自動開立會跳過（不重複開票）' );
$buyer = LegacyOrderData::woomp_buyer( $order );
$ok( in_array( '/ABC+123', $buyer, true ), 'woomp 結帳的載具資料讀得到' );

// 3. 開立日期：備註 → GetIssueList。
$ok( $at === LegacyOrderData::woomp_issue_date( $order, $no ), '備註取到開立時間 ' . LegacyOrderData::woomp_issue_date( $order, $no ) );
// G4：後來的備註提到同一個號碼、帶別的日期，不能被抓去當開立日期。
$order->add_order_note( '店員備註 2026-01-02：發票 ' . $no . ' 客人說要改抬頭' );
$ok( $at === LegacyOrderData::woomp_issue_date( wc_get_order( $order_id ), $no ), '其他備註裡的日期不會被誤抓' );
$ok( true === Op\Issue::run( wc_get_order( $order_id ) )['ok'] ? false : true, 'Issue::run 本身也擋（不重複開票）' );
$probe               = $legacy;
$probe['source_key'] = 'ecpay_official'; // 不看備註，逼它走 GetIssueList。
$probe['date']       = '';
$found               = Op\LegacyInvoiceDate::resolve( wc_get_order( $order_id ), $probe );
$ok( substr( $at, 0, 10 ) === $found, 'GetIssueList 找到日期 ' . $found );

// 4. 退款時列手動處理。
$refund = new WC_Order_Refund();
$refund->set_amount( '1' );
[ $state ] = \Moksafowo\Refund\InvoiceSync::plan( wc_get_order( $order_id ), $refund );
$ok( 'todo_manual' === $state, '退款發票動作 = 手動處理（' . $state . '）' );

// 5. 折讓 → 擋作廢 → 作廢折讓 → 作廢發票。
$a = Op\LegacyAllowance::run( wc_get_order( $order_id ), 1 );
$ok( ! empty( $a['ok'] ) && '' !== ( $a['allowance_no'] ?? '' ), '折讓 1 元：' . ( $a['message'] ?? '' ) );
$big = Op\LegacyAllowance::run( wc_get_order( $order_id ), 100000 );
$ok( empty( $big['ok'] ), '超過可折讓金額 → 拒絕' );
$blocked = Op\LegacyInvalid::run( wc_get_order( $order_id ), '盤點測試' );
$ok( empty( $blocked['ok'] ), '還有折讓 → 不能作廢' );
// G4：之後 ERP 才寫入外部標記 → 舊發票變成外部已開立，作廢折讓不能再打綠界。
$tmp = wc_get_order( $order_id );
$tmp->update_meta_data( Keys::INVOICE_EXTERNAL, 'ERP開立' );
$tmp->save();
$http_before = did_action( 'http_api_debug' );
$blocked_v   = Op\LegacyAllowance::void( wc_get_order( $order_id ), (string) ( $a['allowance_no'] ?? '' ) );
$blocked_i   = Op\Issue::run( wc_get_order( $order_id ) );
$ok( empty( $blocked_v['ok'] ) && empty( $blocked_i['ok'] ) && did_action( 'http_api_debug' ) === $http_before, '外部標記蓋過舊發票：作廢折讓、開票都拒絕，沒打 API' );
$tmp = wc_get_order( $order_id );
$tmp->delete_meta_data( Keys::INVOICE_EXTERNAL );
$tmp->save();
$v = Op\LegacyAllowance::void( wc_get_order( $order_id ), (string) ( $a['allowance_no'] ?? '' ) );
$ok( ! empty( $v['ok'] ), '作廢折讓：' . ( $v['message'] ?? '' ) );
$i = Op\LegacyInvalid::run( wc_get_order( $order_id ), '盤點測試' );
$ok( ! empty( $i['ok'] ), '作廢 woomp 發票：' . ( $i['message'] ?? '' ) );
$ok( null === LegacyOrderData::invoice( wc_get_order( $order_id ) ), '作廢後不再擋開立' );

// 6. ERP 佔位值：擋開立，但不能作廢折讓。
$o = wc_get_order( $order_id );
$o->delete_meta_data( Keys::LEGACY_INVOICE_VOIDED_AT );
$o->update_meta_data( '_ecpay_invoice_number', 'ERP開立' );
$o->delete_meta_data( '_ecpay_invoice_status' );
$o->save();
$erp = LegacyOrderData::invoice( wc_get_order( $order_id ) );
$ok( null !== $erp && ! LegacyOrderData::ecpay_actionable( $erp ), 'ERP開立：擋開立、不給作廢折讓' );

// 7. woomp 自己作廢過（狀態 0、號碼清空）→ 不擋。
$o->update_meta_data( '_ecpay_invoice_number', '' );
$o->update_meta_data( '_ecpay_invoice_status', 0 );
$o->save();
$ok( null === LegacyOrderData::invoice( wc_get_order( $order_id ) ), 'woomp 已作廢的 → 不擋開立' );

$clean();
0 === $fail ? WP_CLI::success( '全部通過' ) : WP_CLI::error( "{$fail} 項未通過" );
