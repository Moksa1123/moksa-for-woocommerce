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
		// Source authenticity verified via CheckMacValue (MD5, per ECPay logistics spec) + hash_equals on line ~25 (Helper::verify_check_mac_value)
		// before any field is read or state changed. Raw array passed unmodified to verifier; map_deep sanitize follows on line ~32.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- gateway shipping IPN; no WP nonce possible; source verified via CheckMacValue hash_equals before any use; sanitized via map_deep below.
		$raw = $_POST;

		if ( empty( $raw ) ) {
			status_header( 400 );
			echo 'Empty';
			exit;
		}

		if ( ! Helper::verify_check_mac_value( $raw ) ) {
			Helper::warn( 'shipping IPN CheckMacValue mismatch — rejected' );
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
		// 貨態通知一定帶貨態碼與物流交易編號。開選店地圖時本外掛也會替訪客簽一份同樣用 CheckMacValue
		// 的參數，沒有這兩欄的就不是貨態通知，不能拿來動訂單。
		if ( '' === $rtn_code || '' === $logistics_id ) {
			Helper::warn( 'shipping IPN without RtnCode / AllPayLogisticsID — rejected', [ 'order_id' => $order_id ] );
			status_header( 400 );
			echo 'Not a status notice';
			exit;
		}

		if ( '' !== $logistics_id ) {
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_ID, $logistics_id );
		}

		// 同一包同一個貨態被重送時不重寫備註、不重跑轉換（同補查）。
		$previous = null;
		foreach ( CreateOrder::get_records( $order ) as $r ) {
			if ( '' !== $logistics_id && (string) ( $r['id'] ?? '' ) === $logistics_id ) {
				$previous = (string) ( $r['rtn_code'] ?? '' );
				break;
			}
		}
		CreateOrder::update_record_status( $order, $logistics_id, $rtn_code, $rtn_msg );
		if ( $previous === $rtn_code ) {
			$order->save();
			echo '1|OK';
			exit;
		}

		do_action( 'moksafowo_ecpay_shipping_status_received', $order, $rtn_code, $rtn_msg );

		// 狀態有變時 mapper 已寫一則；沒變才補這則，並註明是哪一包。
		if ( ! \Moksafowo\Modules\Shipping\Webhook\AbstractStatusMapper::wrote_note( $order ) ) {
			$order->add_order_note(
				\Moksafowo\Order\Note::build(
					__( 'ECPay shipping status update', 'moksa-for-woocommerce' ),
					[
						__( 'Shipping status', 'moksa-for-woocommerce' ) => $rtn_msg,
						__( 'ECPay shipping ID', 'moksa-for-woocommerce' ) => $logistics_id,
						__( 'Status code', 'moksa-for-woocommerce' )     => $rtn_code,
					]
				)
			);
		}

		$order->save();

		echo '1|OK';
		exit;
	}

	private static function lookup_order_id( string $merchant_trade_no ): ?int {
		if ( '' === $merchant_trade_no ) {
			return null;
		}

		// MTN 格式：前綴＋訂單 ID（補零至少 6 位，超過 999999 就是 7 位以上）＋R＋hex[＋T溫層]
		if ( preg_match( '/^[A-Za-z]+(\d{6,})R[a-f0-9]+(?:T\d)?$/', $merchant_trade_no, $m ) ) {
			$order_id = (int) ltrim( $m[1], '0' );
			if ( $order_id > 0 && wc_get_order( $order_id ) ) {
				return $order_id;
			}
		}

		$found = wc_get_orders(
			[
				'limit'      => 1,
				'status'     => 'all',
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

		// Fallback：拆單 T1/T2 IPN 對不到單一欄位（只鏡射最後一包），直接在拆單紀錄裡找這個 MTN。
		// 原本「最新 50 張有紀錄的訂單」會漏掉較舊的單（date_after 也不是 WC 支援的參數）。
		$candidates = wc_get_orders(
			[
				'limit'        => 5,
				'status'       => 'all',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
				'meta_key'     => Keys::ECPAY_LOGISTIC_RECORDS,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
				'meta_value'   => $merchant_trade_no,
				'meta_compare' => 'LIKE',
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
