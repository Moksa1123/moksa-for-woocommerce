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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- SmilePay shipping IPN; no WP nonce possible; Verify_key hash_equals verified before any state change; all fields sanitized at extraction.
		$payload = wp_unslash( $_REQUEST );

		// SmilePay 回傳 BIG5 編碼，強制轉 UTF-8
		foreach ( $payload as $k => $v ) {
			if ( is_string( $v ) && mb_detect_encoding( urldecode( $v ), 'BIG5', true ) ) {
				$payload[ $k ] = mb_convert_encoding( urldecode( $v ), 'UTF-8', 'BIG5' );
			}
		}

		$verify   = isset( $payload['Verify_key'] ) ? sanitize_text_field( (string) $payload['Verify_key'] ) : '';
		$expected = Helper::verify_key();
		if ( '' === $expected || ! hash_equals( $expected, $verify ) ) {
			Helper::log( 'IPN verify_key mismatch', [ 'received' => substr( $verify, 0, 4 ) . '****' ] );
			status_header( 403 );
			exit( '<Roturlstatus>verify_key fail</Roturlstatus>' );
		}

		$order_id = isset( $payload['Data_id'] ) ? absint( $payload['Data_id'] ) : 0;
		$smseid   = isset( $payload['Smseid'] ) ? sanitize_text_field( (string) $payload['Smseid'] ) : '';
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

		$order_smseid = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_NO );
		if ( '' !== $order_smseid && '' !== $smseid && ! hash_equals( $order_smseid, $smseid ) ) {
			Helper::log(
				'IPN smseid mismatch',
				[
					'order_id' => $order_id,
					'expected' => $order_smseid,
					'got'      => $smseid,
				]
			);
			status_header( 403 );
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

		if ( '' !== $wc_status && $order->get_status() !== $wc_status ) {
			$order->update_status(
				$wc_status,
				sprintf(
				/* translators: %s: SmilePay status label */
					__( 'SmilePay shipping status: %s', 'moksa-for-woocommerce' ),
					$label
				)
			);
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
			case 4:
				return [ __( 'Returned by the customer', 'moksa-for-woocommerce' ), 'cancelled' ];
			case 5:
				return [ __( 'Arrived at the return store', 'moksa-for-woocommerce' ), '' ];
			case 6:
				return [ __( 'Return collected', 'moksa-for-woocommerce' ), '' ];
			case 7:
				return [ __( 'Return arrived at the distribution center', 'moksa-for-woocommerce' ), '' ];
			default:
				/* translators: %d: SmilePay status code */
				return [ sprintf( __( 'Status code %d', 'moksa-for-woocommerce' ), $code ), '' ];
		}
	}
}
