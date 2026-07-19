<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments\Api;

use Moksafowo\Http\Response;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class WebhookHandler {

	private const REPLAY_WINDOW_MS = 300000; // 5 min; per SLP webhook spec.

	private const KNOWN_API_VERSIONS = [ 'V1', 'V1.0', 'V1.1', 'V1.2' ];

	public static function handle(): void {
		// Signature must be computed over raw bytes before any parsing (SLP spec).
		$raw_body = file_get_contents( 'php://input' );
		if ( ! is_string( $raw_body ) || '' === $raw_body ) {
			Helper::log( 'webhook empty body' );
			self::reply( 400, 'empty' );
		}

		$sign        = self::header( 'sign' );
		$timestamp   = self::header( 'timestamp' );
		$api_version = self::header( 'apiVersion' );

		if ( '' === $sign || '' === $timestamp ) {
			Helper::log( 'webhook missing sign / timestamp header' );
			self::reply( 400, 'missing headers' );
		}

		if ( ! self::verify_signature( $timestamp, $raw_body, $sign ) ) {
			Helper::log( 'webhook signature mismatch — rejected' );
			self::reply( 401, 'invalid signature' );
		}

		if ( ! self::within_replay_window( $timestamp ) ) {
			Helper::log( 'webhook replay window exceeded — rejected', [ 'timestamp' => $timestamp ] );
			self::reply( 401, 'stale timestamp' );
		}

		$event = json_decode( $raw_body, true );
		if ( ! is_array( $event ) ) {
			Helper::log( 'webhook body not JSON' );
			self::reply( 400, 'bad request' );
		}

		$event = map_deep( $event, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );

		$event_id = (string) ( $event['id'] ?? '' );
		$type     = (string) ( $event['type'] ?? '' );
		$data     = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : [];

		if ( '' !== $api_version && ! in_array( $api_version, self::KNOWN_API_VERSIONS, true ) ) {
			Helper::log(
				'webhook unknown apiVersion — acknowledged without processing',
				[
					'api_version' => $api_version,
					'type'        => $type,
				]
			);
			self::reply( 200, 'ok' );
		}

		Helper::log(
			'webhook received',
			[
				'id'          => $event_id,
				'type'        => $type,
				'api_version' => $api_version,
			]
		);

		$order = self::resolve_order( $data );
		if ( ! $order instanceof \WC_Order ) {
			Helper::log(
				'webhook order not found',
				[
					'id'   => $event_id,
					'type' => $type,
				]
			);
				self::reply( 200, 'order not found' );
		}

		if ( '' !== $event_id && self::is_duplicate( $order, $event_id ) ) {
			Helper::log(
				'webhook duplicate event — skipped',
				[
					'id'   => $event_id,
					'type' => $type,
				]
			);
			self::reply( 200, 'duplicate' );
		}

		self::write_meta( $order, $data );
		self::dispatch( $order, $type, $data );

		if ( '' !== $event_id ) {
			self::mark_processed( $order, $event_id );
		}
		$order->save();

		self::reply( 200, 'ok' );
	}

	private static function verify_signature( string $timestamp, string $raw_body, string $sign ): bool {
		$sign_key = Helper::sign_key();
		if ( '' === $sign_key || '' === $sign ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $sign_key );
		return hash_equals( $expected, strtolower( trim( $sign ) ) );
	}

	private static function within_replay_window( string $timestamp ): bool {
		if ( ! ctype_digit( $timestamp ) ) {
			return false;
		}
		$ts_ms  = (int) $timestamp;
		$now_ms = (int) round( microtime( true ) * 1000 );
		return abs( $now_ms - $ts_ms ) < self::REPLAY_WINDOW_MS;
	}

	private static function resolve_order( array $data ): ?\WC_Order {
		$reference_id = (string) ( $data['referenceId'] ?? $data['referenceOrderId'] ?? '' );
		if ( '' !== $reference_id ) {
			$id = Helper::parse_order_id( $reference_id );
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
					'meta_key'   => Keys::SLP_REFERENCE_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
					'meta_value' => $reference_id,
				]
			);
			if ( ! empty( $found ) ) {
				$order = wc_get_order( (int) $found[0] );
				if ( $order instanceof \WC_Order ) {
					return $order;
				}
			}
		}

		$lookup_fields = [
			'sessionId'    => Keys::SLP_SESSION_ID,
			'tradeOrderId' => Keys::SLP_TRADE_ORDER_ID,
		];
		foreach ( $lookup_fields as $field => $meta_key ) {
			$value = (string) ( $data[ $field ] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			$found = wc_get_orders(
				[
					'limit'      => 1,
					'return'     => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
					'meta_key'   => $meta_key,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
					'meta_value' => $value,
				]
			);
			if ( ! empty( $found ) ) {
				$order = wc_get_order( (int) $found[0] );
				if ( $order instanceof \WC_Order ) {
					return $order;
				}
			}
		}
		return null;
	}

	private static function write_meta( \WC_Order $order, array $data ): void {
		$payment = self::first_payment_detail( $data );

		$session_id = (string) ( $data['sessionId'] ?? '' );
		if ( '' !== $session_id ) {
			$order->update_meta_data( Keys::SLP_SESSION_ID, $session_id );
		}

		$trade_order_id = (string) ( $payment['tradeOrderId'] ?? $data['tradeOrderId'] ?? '' );
		if ( '' !== $trade_order_id ) {
			$order->update_meta_data( Keys::SLP_TRADE_ORDER_ID, $trade_order_id );
			$order->set_transaction_id( $trade_order_id );
		}

		$status = (string) ( $payment['status'] ?? $data['status'] ?? '' );
		if ( '' !== $status ) {
			$order->update_meta_data( Keys::SLP_STATUS, $status );
		}

		$sub_status = (string) ( $payment['subStatus'] ?? $data['subStatus'] ?? '' );
		if ( '' !== $sub_status ) {
			$order->update_meta_data( Keys::SLP_SUB_STATUS, $sub_status );
		}

		$method = (string) ( $payment['paymentMethod'] ?? $data['paymentMethod'] ?? '' );
		if ( '' !== $method ) {
			$order->update_meta_data( Keys::SLP_PAYMENT_METHOD, $method );
		}

		$refund_order_id = (string) ( $data['refundOrderId'] ?? '' );
		if ( '' !== $refund_order_id ) {
			$order->update_meta_data( Keys::SLP_REFUND_ORDER_ID, $refund_order_id );
		}
	}

	private static function dispatch( \WC_Order $order, string $type, array $data ): void {
		switch ( $type ) {
			case 'trade.succeeded':
			case 'session.succeeded':
				if ( ! $order->is_paid() ) {
					$payment = self::first_payment_detail( $data );
					$txn     = (string) ( $payment['tradeOrderId'] ?? $data['tradeOrderId'] ?? '' );
					$order->payment_complete( $txn );
				}
				$order->add_order_note(
					sprintf(
						/* translators: %s: payment method */
						__( 'Shopline Payments 付款完成（%s）。', 'moksa-for-woocommerce' ),
						(string) $order->get_meta( Keys::SLP_PAYMENT_METHOD ) ?: $type
					)
				);
				break;

			case 'trade.failed':
				$order->update_status(
					'failed',
					sprintf(
						/* translators: %s: sub status */
						__( 'Shopline Payments 付款失敗（%s）。', 'moksa-for-woocommerce' ),
						(string) $order->get_meta( Keys::SLP_SUB_STATUS ) ?: __( '未提供原因', 'moksa-for-woocommerce' )
					)
				);
				break;

			case 'trade.expired':
			case 'session.expired':
				if ( ! $order->is_paid() ) {
					$order->update_status( 'cancelled', __( 'Shopline Payments 交易逾時未完成。', 'moksa-for-woocommerce' ) );
				}
				break;

			case 'trade.refund.succeeded':
				$order->add_order_note(
					sprintf(
						/* translators: %s: refund order id */
						__( 'Shopline Payments 退款成功（退款編號 %s）。', 'moksa-for-woocommerce' ),
						(string) $order->get_meta( Keys::SLP_REFUND_ORDER_ID ) ?: __( '無編號', 'moksa-for-woocommerce' )
					)
				);
				break;

			case 'trade.refund.failed':
				$order->add_order_note( __( 'Shopline Payments 退款失敗 — 請至 SLP 後台確認。', 'moksa-for-woocommerce' ) );
				break;

			case 'session.created':
				$order->add_order_note(
					sprintf(
						/* translators: %s: event type */
						__( 'Shopline Payments 通知：%s', 'moksa-for-woocommerce' ),
						$type
					)
				);
				break;

			default:
				$order->add_order_note(
					sprintf(
						/* translators: %s: event type */
						__( 'Shopline Payments 通知：%s', 'moksa-for-woocommerce' ),
						$type
					)
				);
		}
	}

	private static function first_payment_detail( array $data ): array {
		if ( isset( $data['paymentDetails'][0] ) && is_array( $data['paymentDetails'][0] ) ) {
			return $data['paymentDetails'][0];
		}
		return [];
	}

	private static function is_duplicate( \WC_Order $order, string $event_id ): bool {
		$processed = $order->get_meta( Keys::SLP_PROCESSED_EVENT_IDS );
		$processed = is_array( $processed ) ? $processed : [];
		return in_array( $event_id, $processed, true );
	}

	private static function mark_processed( \WC_Order $order, string $event_id ): void {
		$processed   = $order->get_meta( Keys::SLP_PROCESSED_EVENT_IDS );
		$processed   = is_array( $processed ) ? $processed : [];
		$processed[] = $event_id;
		if ( count( $processed ) > 50 ) {
			$processed = array_slice( $processed, -50 );
		}
		$order->update_meta_data( Keys::SLP_PROCESSED_EVENT_IDS, $processed );
	}

	private static function header( string $name ): string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}

	private static function reply( int $status, string $body ): void {
		Response::send_plain( $status, $body );
	}
}
