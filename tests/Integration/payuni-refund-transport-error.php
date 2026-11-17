<?php
/**
 * 整合測試（測試站 wp eval-file）：統一金流退款連線失敗要回 WP_Error，不能回 false。
 *
 * 用法：wp eval-file payuni-refund-transport-error.php <訂單ID>
 * 訂單要是統一金流信用卡、已付款的沙箱測試單。只攔「退款／取消授權」那一支 API 讓它斷線，
 * 查單照常打沙箱；不會真的退款。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

$order_id = absint( $args[0] ?? 0 );
$order    = wc_get_order( $order_id );
if ( ! $order || 0 !== strpos( (string) $order->get_payment_method(), 'moksafowo_payuni_' ) ) {
	WP_CLI::error( '請給一張統一金流的測試訂單 ID。' );
}

$blocked = 0;
$block   = static function ( $pre, $parsed_args, $url ) use ( &$blocked ) {
	if ( preg_match( '#/api/trade/(close|cancel)$#', (string) $url ) ) {
		++$blocked;
		return new WP_Error( 'http_request_failed', 'simulated: connection timed out' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $block, 10, 3 );

$notes_before = count( wc_get_order_notes( array( 'order_id' => $order_id ) ) );
$result       = ( new \Moksafowo\Modules\Payuni\Api\PaymentRequest() )->refund( $order_id, 1, 'integration test' );
remove_filter( 'pre_http_request', $block, 10 );
$notes_after = count( wc_get_order_notes( array( 'order_id' => $order_id ) ) );

$ok = true;
if ( 1 !== $blocked ) {
	WP_CLI::warning( "沒有打到退款 API（攔到 {$blocked} 次）：可能查單結果不能退。結果：" . ( is_wp_error( $result ) ? $result->get_error_message() : var_export( $result, true ) ) );
	$ok = false;
}
if ( ! is_wp_error( $result ) ) {
	WP_CLI::warning( '連線失敗沒有回 WP_Error：' . var_export( $result, true ) );
	$ok = false;
} else {
	WP_CLI::log( '回傳 WP_Error：' . $result->get_error_message() );
}
if ( $notes_after <= $notes_before ) {
	WP_CLI::warning( '沒有留訂單備註。' );
	$ok = false;
}
$ok ? WP_CLI::success( '通過' ) : WP_CLI::error( '未通過' );
