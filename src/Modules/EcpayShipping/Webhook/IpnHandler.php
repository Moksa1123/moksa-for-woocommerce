<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Webhook;

use Moksafowo\Modules\EcpayShipping\Api\Helper;
use Moksafowo\Modules\EcpayShipping\Operations\CreateOrder;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public static function handle(): void {
		// Gateway shipping IPN: no WP nonce possible (external server cannot send one).
		// Source authenticity verified via CheckMacValue SHA256 + hash_equals on line ~25 (Helper::verify_check_mac_value)
		// before any field is read or state changed. Raw array passed unmodified to verifier; map_deep sanitize follows on line ~32.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- gateway shipping IPN; no WP nonce possible; source verified via CheckMacValue hash_equals before any use; sanitized via map_deep below.
		$raw = $_POST;

		if ( empty( $raw ) ) {
			status_header( 400 );
			echo 'Empty';
			exit;
		}

		if ( ! Helper::verify_check_mac_value( $raw ) ) {
			Helper::log( 'shipping IPN CheckMacValue mismatch — rejected' );
			status_header( 400 );
			echo 'CheckMacValue mismatch';
			exit;
		}

		$posted = array_map(
			static fn( $v ) => is_string( $v ) ? sanitize_text_field( wp_unslash( $v ) ) : $v,
			$raw
		);

		Helper::log( 'shipping IPN received', [ 'data' => $posted ] );

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

		if ( '' !== $logistics_id ) {
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_ID, $logistics_id );
		}

		CreateOrder::update_record_status( $order, $logistics_id, $rtn_code, $rtn_msg );

		$order->add_order_note(
			sprintf(
				/* translators: 1: status code, 2: status message */
				__( '綠界物流貨態：%2$s（狀態代碼 %1$s）', 'mo-ectools' ),
				$rtn_code,
				$rtn_msg
			)
		);

		do_action( 'moksafowo_ecpay_shipping_status_received', $order, $rtn_code, $rtn_msg );

		$order->save();

		echo '1|OK';
		exit;
	}

	private static function lookup_order_id( string $merchant_trade_no ): ?int {
		if ( '' === $merchant_trade_no ) {
			return null;
		}

		// MTN 格式：mowpL<6位訂單ID>R<hex>[T<temp>]
		if ( preg_match( '/^[A-Za-z]+(\d{6})R[a-f0-9]+(?:T\d)?$/', $merchant_trade_no, $m ) ) {
			$order_id = (int) ltrim( $m[1], '0' );
			if ( $order_id > 0 && wc_get_order( $order_id ) ) {
				return $order_id;
			}
		}

		$found = wc_get_orders(
			[
				'limit'      => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
				'meta_key'   => Keys::ECPAY_LOGISTIC_MERCHANT_TRADE_NO,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
				'meta_value' => $merchant_trade_no,
			]
		);
		if ( ! empty( $found ) ) {
			$order = $found[0];
			return $order instanceof \WC_Order ? $order->get_id() : null;
		}

		// Fallback：拆單 T1/T2 IPN 對不到 mirror key，掃近 30 天 records 找 MTN
		$candidates = wc_get_orders(
			[
				'limit'        => 50,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_after'   => gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
				'meta_key'     => Keys::ECPAY_LOGISTIC_RECORDS,
				'meta_compare' => 'EXISTS',
			]
		);
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
