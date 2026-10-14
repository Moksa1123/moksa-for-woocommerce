<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Api;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public static function handle(): void {
		// SmilePay shipping IPN: no WP nonce possible (external server cannot send one).
		// Source authenticity verified via Verify_key (hash_equals on line ~25) before any order state change.
		// All string fields sanitized at extraction after BIG5→UTF-8 conversion.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- SmilePay shipping IPN; no WP nonce possible; per-order callback key (mfk) hash_equals and Smseid match verified before any state change; all fields sanitized at extraction.
		$payload = wp_unslash( $_REQUEST );

		// SmilePay 回傳 BIG5 編碼，強制轉 UTF-8
		foreach ( $payload as $k => $v ) {
			if ( is_string( $v ) && mb_detect_encoding( urldecode( $v ), 'BIG5', true ) ) {
				$payload[ $k ] = mb_convert_encoding( urldecode( $v ), 'UTF-8', 'BIG5' );
			}
		}

		// 規格：通知只帶 Data_id／Smseid／Shipstatus 等，不帶 Verify_key，而且不論結果都要回 HTTP 200。
		// 驗證改用回呼網址上的每單 key（mfk），再要求 Smseid 是這張訂單建過的物流單之一。
		$order_id = isset( $payload['Data_id'] ) ? absint( $payload['Data_id'] ) : 0;
		$smseid   = isset( $payload['Smseid'] ) ? sanitize_text_field( (string) $payload['Smseid'] ) : '';
		$mfk      = isset( $payload['mfk'] ) ? sanitize_text_field( (string) $payload['mfk'] ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			Helper::log(
				'IPN order not found',
				[
					'order_id' => $order_id,
					'smseid'   => $smseid,
				]
			);
			status_header( 200 );
			exit( '<Roturlstatus>order not found</Roturlstatus>' );
		}

		if ( 'yes' === $order->get_meta( Keys::SMILEPAY_SHIPPING_KEYED ) && ! hash_equals( Helper::callback_key( $order ), $mfk ) ) {
			Helper::log( 'IPN callback key mismatch', [ 'order_id' => $order_id ] );
			status_header( 200 );
			exit( '<Roturlstatus>key mismatch</Roturlstatus>' );
		}

		// 黑貓多溫層會拆成多件、各有自己的 Smseid，單一 meta 只存最後一件。
		$known = [ (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_NO ) ];
		foreach ( (array) $order->get_meta( Keys::SMILEPAY_SHIPPING_RECORDS ) as $record ) {
			if ( is_array( $record ) && ! empty( $record['smseid'] ) ) {
				$known[] = (string) $record['smseid'];
			}
		}
		$known = array_values( array_filter( array_unique( $known ) ) );
		if ( '' === $smseid || ! in_array( $smseid, $known, true ) ) {
			Helper::log(
				'IPN smseid mismatch',
				[
					'order_id' => $order_id,
					'got'      => $smseid,
				]
			);
			status_header( 200 );
			exit( '<Roturlstatus>smseid mismatch</Roturlstatus>' );
		}

		$ship_status = isset( $payload['Shipstatus'] ) ? (int) $payload['Shipstatus'] : 0;
		$paymentno   = isset( $payload['Payment_no'] ) ? sanitize_text_field( (string) $payload['Payment_no'] ) : '';

		[ $label, $wc_status ] = self::map_status( $ship_status );

		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_STATUS, $label );
		if ( '' !== $paymentno ) {
			$order->update_meta_data( Keys::SMILEPAY_SHIPPING_PAY_NO, $paymentno );
		}
		$order->save();

		if ( '' !== $wc_status && $order->get_status() !== $wc_status && \Moksafowo\Modules\Shipping\Statuses\Registrar::is_usable( $wc_status ) ) {
			$order->update_status(
				$wc_status,
				sprintf(
				/* translators: %s: SmilePay status label */
					__( 'SmilePay shipping status: %s', 'moksa-for-woocommerce' ),
					$label
				)
			);
			// 跟綠界／PAYUNi 一樣寄物流通知信（已出貨、已到店、門市關轉）。
			if ( str_starts_with( $wc_status, 'moksa-' ) ) {
				do_action( 'moksafowo_shipping_status_' . $wc_status . '_notification', $order->get_id(), $order );
			}
		} else {
			$order->add_order_note(
				sprintf(
				/* translators: %s: SmilePay status label */
					__( 'SmilePay shipping status: %s', 'moksa-for-woocommerce' ),
					$label
				)
			);
		}

		Helper::log(
			'IPN processed',
			[
				'order_id'    => $order_id,
				'ship_status' => $ship_status,
				'label'       => $label,
			]
		);

		status_header( 200 );
		exit( '<Roturlstatus>mowp1.0</Roturlstatus>' );
	}


	private static function map_status( int $code ): array {
		switch ( $code ) {
			case 1:
				return [ __( 'Shipped', 'moksa-for-woocommerce' ), 'moksa-shipped' ];
			case 2:
				return [ __( 'Arrived at the store', 'moksa-for-woocommerce' ), 'moksa-cvs-arrived' ];
			case 3:
				return [ __( 'Collected by the customer', 'moksa-for-woocommerce' ), 'completed' ];
			// 4~7 是「顧客沒取貨 → 包裹一路退回」的同一條鏈，整條停在未取件。
			// 4 曾對到 cancelled，那會觸發 WC 自動回補庫存 —— 包裹還在路上就先加回可售量。
			case 4:
				return [ __( 'Returned by the customer', 'moksa-for-woocommerce' ), 'moksa-unclaimed' ];
			case 5:
				return [ __( 'Arrived at the return store', 'moksa-for-woocommerce' ), 'moksa-unclaimed' ];
			case 6:
				return [ __( 'Return collected', 'moksa-for-woocommerce' ), 'moksa-unclaimed' ];
			case 7:
				return [ __( 'Return arrived at the distribution center', 'moksa-for-woocommerce' ), 'moksa-unclaimed' ];
			default:
				/* translators: %d: SmilePay status code */
				return [ sprintf( __( 'Status code %d', 'moksa-for-woocommerce' ), $code ), '' ];
		}
	}
}
