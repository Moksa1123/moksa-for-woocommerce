<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\NewebpayShipping\Api;

use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- IPN webhook; signature verified inside; raw bytes required for hash check.
		$trade_info = isset( $_POST['TradeInfo'] ) ? wp_unslash( (string) $_POST['TradeInfo'] ) : '';
		$trade_sha  = isset( $_POST['TradeSha'] ) ? wp_unslash( (string) $_POST['TradeSha'] ) : '';

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

		$data = $decrypted['Result'] ?? [];
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

		$status = (string) ( $decrypted['Status'] ?? '' );
		$lgs_no = (string) ( $data['LgsNo'] ?? '' );
		$lgs_type = (string) ( $data['LgsType'] ?? '' );
		$store_id = (string) ( $data['StoreCode'] ?? $data['StoreID'] ?? '' );
		$store_name = (string) ( $data['StoreName'] ?? '' );
		$store_addr = (string) ( $data['StoreAddr'] ?? $data['StoreAddress'] ?? '' );
		// NPA-B58 notify 也帶 Retld + RetString — 用 StatusMapper 轉中文 + 推 WC status
		$retld = (string) ( $data['Retld'] ?? $data['RetId'] ?? '' );
		$ret_string = (string) ( $data['RetString'] ?? '' );
		$mapped = '' !== $retld ? \MoksaWeb\Mowc\Modules\NewebpayShipping\Operations\StatusMapper::map( $retld ) : null;

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
		if ( '' !== $status ) {
			$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_STATUS, $status );
		}

		$parts = [ __( '藍新物流貨態回傳', 'mo-ectools' ) ];
		if ( '' !== $lgs_no ) {
			/* translators: %s: shipping tracking number */
			$parts[] = sprintf( __( '物流單號 %s', 'mo-ectools' ), $lgs_no );
		}
		if ( null !== $mapped ) {
			/* translators: %s: shipping status label */
			$parts[] = sprintf( __( '狀態 %s', 'mo-ectools' ), $mapped['label'] );
		} elseif ( '' !== $status ) {
			/* translators: %s: shipping status code */
			$parts[] = sprintf( __( '狀態 %s', 'mo-ectools' ), $status );
		}
		if ( '' !== $store_name ) {
			/* translators: %s: convenience store name */
			$parts[] = sprintf( __( '門市 %s', 'mo-ectools' ), $store_name );
		}
		$order->add_order_note( implode( '，', $parts ) );

		// 自動推 WC 狀態（per Retld 對照）
		if ( null !== $mapped && '' !== $mapped['wc_status'] ) {
			$current = $order->get_status();
			if ( $current !== $mapped['wc_status'] ) {
				$order->update_status( $mapped['wc_status'], sprintf(
					/* translators: 1: from, 2: to, 3: retld */
					__( '藍新物流自動更新狀態 %1$s → %2$s（Retld=%3$s）', 'mo-ectools' ),
					$current,
					$mapped['wc_status'],
					$retld
				) );
			}
		}
		$order->save();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		do_action( 'mo_newebpay_shipping_status_received', $order, $data, $status );

		status_header( 200 );
		exit( '1|OK' );
	}
}
