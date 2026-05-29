<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Webhook;

use MoksaWeb\Mowc\Modules\EcpayShipping\Api\Helper;
use MoksaWeb\Mowc\Modules\EcpayShipping\Operations\CreateOrder;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- IPN 走 CheckMacValue
		$posted = $_POST;
		// phpcs:enable

		if ( empty( $posted ) ) {
			status_header( 400 );
			echo 'Empty';
			exit;
		}

		Helper::log( 'shipping IPN raw', [ 'data' => $posted ] );

		if ( ! Helper::verify_check_mac_value( $posted ) ) {
			Helper::log( 'shipping IPN CheckMacValue mismatch — rejected' );
			status_header( 400 );
			echo 'CheckMacValue mismatch';
			exit;
		}

		$merchant_trade_no = isset( $posted['MerchantTradeNo'] ) ? wc_clean( wp_unslash( $posted['MerchantTradeNo'] ) ) : '';
		$order_id          = self::lookup_order_id( $merchant_trade_no );

		if ( ! $order_id ) {
			Helper::log( 'shipping IPN order_id not found', [ 'merchant_trade_no' => $merchant_trade_no ] );
			status_header( 404 );
			echo 'Order not found';
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			status_header( 404 );
			echo 'Order not loadable';
			exit;
		}

		$rtn_code     = (string) ( $posted['RtnCode'] ?? '' );
		$rtn_msg      = (string) ( $posted['RtnMsg'] ?? '' );
		$logistics_id = (string) ( $posted['AllPayLogisticsID'] ?? '' );

		// 寫 meta 為審計 — 不論 RtnCode 成功失敗
		if ( '' !== $logistics_id ) {
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_ID, $logistics_id );
		}

		// 把最新貨態 + updated_at 寫進對應 record（讓訂單編輯頁可顯示「狀態更新時間」）
		CreateOrder::update_record_status( $order, $logistics_id, $rtn_code, $rtn_msg );

		$order->add_order_note( sprintf(
			/* translators: 1: status code, 2: status message */
			__( '綠界物流貨態：%2$s（狀態代碼 %1$s）', 'mo-ectools' ),
			$rtn_code,
			$rtn_msg
		) );

		// 廣播給 StatusMapper 處理 status 對應
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		do_action( 'mo_ecpay_shipping_status_received', $order, $rtn_code, $rtn_msg );

		$order->save();

		echo '1|OK';
		exit;
	}

	private static function lookup_order_id( string $merchant_trade_no ): ?int {
		if ( '' === $merchant_trade_no ) {
			return null;
		}

		// MTN 格式：
		//   單包：mowpL001234R<hex>           例 mowpL001857Rfdeeb2
		//   多包：mowpL001234R<hex>T{1|2|3}    例 mowpL001857Rfdeeb2T1（Phase C 拆單）
		if ( preg_match( '/^[A-Za-z]+(\d{6})R[a-f0-9]+(?:T\d)?$/', $merchant_trade_no, $m ) ) {
			$order_id = (int) ltrim( $m[1], '0' );
			if ( $order_id > 0 && wc_get_order( $order_id ) ) {
				return $order_id;
			}
		}

		// Fallback 1：legacy single-key meta（單包訂單 mirror 的最新 MTN）
		$found = wc_get_orders( [
			'limit'      => 1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
			'meta_key'   => Keys::ECPAY_LOGISTIC_MERCHANT_TRADE_NO,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
			'meta_value' => $merchant_trade_no,
		] );
		if ( ! empty( $found ) ) {
			$order = $found[0];
			return $order instanceof \WC_Order ? $order->get_id() : null;
		}

		// Fallback 2：拆單訂單的 records list 含多筆 MTN，single mirror 只存最新一筆，
		// 對 T1/T2 的 IPN 對不到。掃近期 30 天有 records 的訂單找 MTN match。
		$candidates = wc_get_orders( [
			'limit'        => 50,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'date_after'   => gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ),
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
			'meta_key'     => Keys::ECPAY_LOGISTIC_RECORDS,
			'meta_compare' => 'EXISTS',
		] );
		foreach ( $candidates as $candidate ) {
			$records = $candidate->get_meta( Keys::ECPAY_LOGISTIC_RECORDS );
			if ( ! is_array( $records ) ) {
				continue;
			}
			foreach ( $records as $r ) {
				if ( ( $r['mtn'] ?? '' ) === $merchant_trade_no ) {
					return (int) $candidate->get_id();
				}
			}
		}
		return null;
	}

}
