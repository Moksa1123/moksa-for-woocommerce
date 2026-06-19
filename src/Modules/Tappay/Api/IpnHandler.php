<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Tappay\Api;

use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public static function handle_notify(): void {
		// Signature must be computed over raw bytes before any parsing.
		$raw_body = file_get_contents( 'php://input' );
		if ( ! is_string( $raw_body ) || '' === $raw_body ) {
			Helper::log( 'notify empty body' );
			status_header( 400 );
			echo 'empty';
			exit;
		}

		$signature = self::header( 'X-Tappay-Signature' );
		if ( ! Helper::verify_notify_signature( $raw_body, $signature ) ) {
			Helper::log( 'notify signature mismatch — rejected' );
			status_header( 401 );
			echo 'invalid signature';
			exit;
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			Helper::log( 'notify body not JSON' );
			status_header( 400 );
			echo 'bad request';
			exit;
		}

		$payload = map_deep( $payload, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );

		Helper::log( 'notify received', [ 'payload' => $payload ] );

		$order_number = (string) ( $payload['order_number'] ?? '' );
		$order        = self::resolve_order( $order_number );
		if ( ! $order instanceof \WC_Order ) {
			Helper::log( 'notify order not found', [ 'order_number' => $order_number ] );
			status_header( 404 );
			echo 'order not found';
			exit;
		}

		self::write_transaction_meta( $order, $payload );
		self::apply_status( $order, $payload );
		$order->save();

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'OK';
		exit;
	}

	public static function handle_result(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- IPN webhook; CheckMacValue / HMAC / RSA signature verified inside this method.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended — 3DS return，無 nonce；以 server 端 query 為準
		$order_number = isset( $_GET['order_number'] ) ? sanitize_text_field( wp_unslash( $_GET['order_number'] ) ) : '';
		$rec_trade_id = isset( $_GET['rec_trade_id'] ) ? sanitize_text_field( wp_unslash( $_GET['rec_trade_id'] ) ) : '';
		// phpcs:enable

		$order = self::resolve_order( $order_number );
		if ( ! $order instanceof \WC_Order ) {
			Helper::log( '3DS result order not found', [ 'order_number' => $order_number ] );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( '' === $rec_trade_id ) {
			$rec_trade_id = (string) $order->get_meta( Keys::TAPPAY_REC_TRADE_ID );
		}

		// Always server-query to confirm status; do not trust query-string params.
		$query = '' !== $rec_trade_id
			? Client::query_by_rec_trade_id( $rec_trade_id )
			: Client::query_by_order_number( Helper::build_order_number( $order ) );

		$record = self::first_trade_record( $query['data'] );
		Helper::log(
			'3DS result query',
			[
				'order_id'     => $order->get_id(),
				'rec_trade_id' => $rec_trade_id,
				'record'       => $record,
			]
		);

		if ( null !== $record ) {
			self::write_transaction_meta( $order, $record );
			self::apply_status( $order, $record );
			$order->save();
		}

		if ( $order->is_paid() || $order->has_status( [ 'processing', 'completed', 'on-hold' ] ) ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		wc_add_notice( __( 'TapPay 3D 驗證未完成或付款失敗，請重新嘗試。', 'mo-ectools' ), 'error' );
		wp_safe_redirect( $order->get_checkout_payment_url( false ) );
		exit;
	}

	private static function resolve_order( string $order_number ): ?\WC_Order {
		if ( '' === $order_number ) {
			return null;
		}
		$id = Helper::parse_order_id( $order_number );
		if ( $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				return $order;
			}
		}
		$found = wc_get_orders(
			[
				'limit'      => 1,
				'return'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
				'meta_key'   => Keys::TAPPAY_ORDER_NUMBER,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
				'meta_value' => $order_number,
			]
		);
		if ( empty( $found ) ) {
			return null;
		}
		$order = wc_get_order( (int) $found[0] );
		return $order instanceof \WC_Order ? $order : null;
	}

	private static function write_transaction_meta( \WC_Order $order, array $payload ): void {
		$map = [
			'rec_trade_id'        => Keys::TAPPAY_REC_TRADE_ID,
			'bank_transaction_id' => Keys::TAPPAY_BANK_TRANSACTION_ID,
			'auth_code'           => Keys::TAPPAY_AUTH_CODE,
		];
		foreach ( $map as $field => $key ) {
			if ( isset( $payload[ $field ] ) && '' !== (string) $payload[ $field ] ) {
				$order->update_meta_data( $key, (string) $payload[ $field ] );
			}
		}

		if ( isset( $payload['status'] ) ) {
			$order->update_meta_data( Keys::TAPPAY_TRANSACTION_STATUS, (string) $payload['status'] );
		}

		$card = isset( $payload['card_info'] ) && is_array( $payload['card_info'] ) ? $payload['card_info'] : $payload;
		if ( isset( $card['last_four'] ) && '' !== (string) $card['last_four'] ) {
			$order->update_meta_data( Keys::TAPPAY_CARD_LAST4, (string) $card['last_four'] );
		}
		if ( isset( $card['bin_code'] ) && '' !== (string) $card['bin_code'] ) {
			$order->update_meta_data( Keys::TAPPAY_CARD_BIN, (string) $card['bin_code'] );
		}
		if ( isset( $card['issuer'] ) && '' !== (string) $card['issuer'] ) {
			$order->update_meta_data( Keys::TAPPAY_CARD_ISSUER, (string) $card['issuer'] );
		}

		if ( isset( $payload['transaction_time_millis'] ) ) {
			$order->update_meta_data( Keys::TAPPAY_PAID_AT, (string) $payload['transaction_time_millis'] );
		}
	}

	private static function apply_status( \WC_Order $order, array $payload ): void {
		// pay-by-prime has `status` (0=success); query trade_record has only `record_status` (0=authorized).
		$has_status    = array_key_exists( 'status', $payload );
		$status        = $has_status ? (int) $payload['status'] : -1;
		$record_status = isset( $payload['record_status'] ) ? (int) $payload['record_status'] : null;
		$rec_trade_id  = (string) ( $payload['rec_trade_id'] ?? $order->get_meta( Keys::TAPPAY_REC_TRADE_ID ) );

		if ( 2 === $record_status ) {
			$order->add_order_note( __( 'TapPay 交易已於 TapPay 後台退款。', 'mo-ectools' ) );
			return;
		}

		$paid = $has_status
			? ( 0 === $status && ( null === $record_status || in_array( $record_status, [ 0, 4 ], true ) ) )
			: ( 0 === $record_status );
		if ( $paid ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $rec_trade_id );
				$order->add_order_note(
					sprintf(
						/* translators: 1: rec_trade_id, 2: card last4 */
						__( 'TapPay 信用卡付款完成 — 交易編號 %1$s（卡號末四碼 %2$s）', 'mo-ectools' ),
						$rec_trade_id,
						(string) $order->get_meta( Keys::TAPPAY_CARD_LAST4 )
					)
				);
			}
			return;
		}

		if ( 1 === $record_status ) {
			$order->update_status( 'pending', __( 'TapPay 等待顧客完成 3D 驗證。', 'mo-ectools' ) );
			return;
		}

		$msg  = (string) ( $payload['msg'] ?? ( $payload['bank_result_msg'] ?? '' ) );
		$code = $has_status ? $status : (int) ( $record_status ?? -1 );
		$order->update_status(
			'failed',
			sprintf(
				/* translators: 1: status code, 2: message */
				__( 'TapPay 付款失敗（狀態碼 %1$d）：%2$s', 'mo-ectools' ),
				$code,
				$msg
			)
		);
	}

	private static function first_trade_record( array $data ): ?array {
		if ( isset( $data['trade_records'][0] ) && is_array( $data['trade_records'][0] ) ) {
			return $data['trade_records'][0];
		}
		if ( isset( $data['rec_trade_id'] ) ) {
			return $data;
		}
		return null;
	}

	private static function header( string $name ): string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}
}
