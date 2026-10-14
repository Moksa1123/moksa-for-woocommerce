<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\NewebpayShipping\Api;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public static function handle(): void {
		// Gateway shipping IPN: no WP nonce possible (external server cannot send one).
		// Source authenticity verified via TradeSha (SHA256 + hash_equals, Helper::verify_trade_sha) on line ~25
		// before any decryption or state change. AES-encrypted hex is idempotent under sanitize_text_field.
		// Decoded payload is deep-sanitized via map_deep on line ~38 after decryption succeeds.
		// NPA-B58 貨態通知送的是 EncryptData_／HashData_（與建單請求同格式），不是金流的 TradeInfo／TradeSha。
		// 兩種都收：舊格式保留相容。
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- gateway shipping IPN; no WP nonce possible; source verified via SHA256 hash_equals before any use; decoded payload sanitized via map_deep after decryption.
		$trade_info = isset( $_POST['EncryptData_'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['EncryptData_'] ) ) : '';
		$trade_sha  = isset( $_POST['HashData_'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['HashData_'] ) ) : '';
		if ( '' === $trade_info ) {
			$trade_info = isset( $_POST['TradeInfo'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['TradeInfo'] ) ) : '';
			$trade_sha  = isset( $_POST['TradeSha'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['TradeSha'] ) ) : '';
		}
		$post_status = isset( $_POST['Status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['Status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $trade_info || '' === $trade_sha ) {
			Helper::log( 'missing TradeInfo / TradeSha' );
			status_header( 400 );
			exit( '0|MissingPayload' );
		}

		if ( ! Helper::verify_trade_sha( $trade_info, $trade_sha ) ) {
			Helper::log( 'TradeSha mismatch' );
			status_header( 400 );
			exit( '0|BadHash' );
		}

		$decrypted = Helper::decrypt_trade_info( $trade_info );
		if ( null === $decrypted ) {
			Helper::log( 'decrypt failed' );
			status_header( 400 );
			exit( '0|DecryptFail' );
		}

		$decrypted = map_deep( $decrypted, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );

		// NPA-B58 的欄位直接放在加密內容裡；舊格式包在 Result 底下。
		$data = isset( $decrypted['Result'] ) ? $decrypted['Result'] : $decrypted;
		if ( ! is_array( $data ) ) {
			status_header( 400 );
			exit( '0|BadResult' );
		}

		$merchant_order_no = (string) ( $data['MerchantOrderNo'] ?? '' );
		if ( '' === $merchant_order_no ) {
			status_header( 400 );
			exit( '0|MissingOrderNo' );
		}

		$order_id = Helper::parse_order_id( $merchant_order_no );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			Helper::log( 'order not found', [ 'merchant_order_no' => $merchant_order_no ] );
			status_header( 404 );
			exit( '0|OrderNotFound' );
		}

		$status     = (string) ( $decrypted['Status'] ?? $post_status );
		$lgs_no     = (string) ( $data['LgsNo'] ?? '' );
		$lgs_type   = (string) ( $data['LgsType'] ?? '' );
		$store_id   = (string) ( $data['StoreCode'] ?? $data['StoreID'] ?? '' );
		$store_name = (string) ( $data['StoreName'] ?? '' );
		$store_addr = (string) ( $data['StoreAddr'] ?? $data['StoreAddress'] ?? '' );
		// NPA-B58 Retld → StatusMapper 轉 WC status
		$retld      = (string) ( $data['Retld'] ?? $data['RetId'] ?? '' );
		$ret_string = (string) ( $data['RetString'] ?? '' );
		$mapped     = '' !== $retld ? \Moksafowo\Modules\NewebpayShipping\Operations\StatusMapper::map( $retld ) : null;

		if ( '' !== $lgs_no ) {
			$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_LGS_NO, $lgs_no );
		}
		if ( '' !== $lgs_type ) {
			$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_LGS_TYPE, $lgs_type );
		}
		if ( '' !== $store_id ) {
			$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_STORE_ID, $store_id );
		}
		if ( '' !== $store_name ) {
			$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_STORE_NAME, $store_name );
		}
		if ( '' !== $store_addr ) {
			$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_STORE_ADDR, $store_addr );
		}
		// Status 是這則通知本身的處理結果（SUCCESS），不是貨態；存貨態說明，跟後台「查詢」一致。
		$status_label = null !== $mapped ? (string) $mapped['label'] : $ret_string;
		if ( '' !== $status_label ) {
			$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_STATUS, $status_label );
		}

		$parts = [ __( 'NewebPay shipping status update', 'moksa-for-woocommerce' ) ];
		if ( '' !== $lgs_no ) {
			/* translators: %s: shipping tracking number */
			$parts[] = sprintf( __( 'Tracking number %s', 'moksa-for-woocommerce' ), $lgs_no );
		}
		if ( null !== $mapped ) {
			/* translators: %s: shipping status label */
			$parts[] = sprintf( __( 'Status %s', 'moksa-for-woocommerce' ), $mapped['label'] );
		} elseif ( '' !== $status ) {
			/* translators: %s: shipping status code */
			$parts[] = sprintf( __( 'Status %s', 'moksa-for-woocommerce' ), $status );
		}
		if ( '' !== $store_name ) {
			/* translators: %s: convenience store name */
			$parts[] = sprintf( __( 'Store %s', 'moksa-for-woocommerce' ), $store_name );
		}
		$order->add_order_note( implode( '，', $parts ) );

		if ( null !== $mapped && '' !== $mapped['wc_status'] && \Moksafowo\Modules\Shipping\Statuses\Registrar::is_usable( $mapped['wc_status'] ) ) {
			$current = $order->get_status();
			if ( $current !== $mapped['wc_status'] ) {
				$order->update_status(
					$mapped['wc_status'],
					sprintf(
					/* translators: 1: from, 2: to, 3: retld */
						__( 'NewebPay shipping moved the order from %1$s to %2$s (Retld=%3$s)', 'moksa-for-woocommerce' ),
						$current,
						$mapped['wc_status'],
						$retld
					)
				);
				// 跟綠界／PAYUNi 一樣寄物流通知信（已出貨、已到店、門市關轉）。
				if ( str_starts_with( $mapped['wc_status'], 'moksa-' ) ) {
					do_action( 'moksafowo_shipping_status_' . $mapped['wc_status'] . '_notification', $order->get_id(), $order );
				}
			}
		}
		$order->save();

		do_action( 'moksafowo_newebpay_shipping_status_received', $order, $data, $status );

		status_header( 200 );
		exit( '1|OK' );
	}
}
