<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments\Api;

use Moksafowo\Http\Request;

defined( 'ABSPATH' ) || exit;

final class Client {


	public static function create_session( array $body ): array {
		return self::post( Helper::session_create_url(), $body );
	}


	public static function query_session( string $session_id ): array {
		return self::post( Helper::session_query_url(), [ 'sessionId' => $session_id ] );
	}


	/** 內嵌式（SDK）建立付款交易；定期扣款也走這支。 */
	public static function create_payment( array $body, string $idempotent_key = '' ): array {
		return self::post( Helper::payment_create_url(), $body, $idempotent_key );
	}

	public static function get_payment( string $trade_order_id ): array {
		return self::post( Helper::payment_get_url(), [ 'tradeOrderId' => $trade_order_id ] );
	}

	public static function query_instruments( string $customer_id ): array {
		return self::post( Helper::instruments_url(), [ 'customerId' => $customer_id ] );
	}

	/** 解綁付款工具（/api/v1/customer/paymentInstrument/unbind）。 */
	public static function unbind_instrument( string $customer_id, string $instrument_id ): array {
		return self::post(
			Helper::base_url() . '/api/v1/customer/paymentInstrument/unbind',
			[
				'customerId'          => $customer_id,
				'paymentInstrumentId' => $instrument_id,
			]
		);
	}

	public static function create_refund( array $body, string $idempotent_key ): array {
		return self::post( Helper::refund_create_url(), $body, $idempotent_key );
	}


	private static function post( string $url, array $body, string $idempotent_key = '' ): array {
		$merchant_id = Helper::merchant_id();
		$api_key     = Helper::api_key();
		if ( '' === $merchant_id || '' === $api_key ) {
			self::log( 'request skipped — credentials unset', [ 'url' => $url ] );
			return [
				'ok'      => false,
				'message' => __( 'The Shopline Payments merchant credentials are not set.', 'moksa-for-woocommerce' ),
				'code'    => 'NO_CREDENTIALS',
				'http'    => 0,
				'data'    => [],
			];
		}

		$headers     = [
			'merchantId' => $merchant_id,
			'apiKey'     => $api_key,
			'requestId'  => Helper::request_id(),
		];
		$platform_id = Helper::platform_id();
		if ( '' !== $platform_id ) {
			$headers['platformId'] = $platform_id;
		}
		if ( '' !== $idempotent_key ) {
			$headers['idempotentKey'] = $idempotent_key;
		}

		try {
			$resp = Request::post( $url, $body, $headers, 'json' );
		} catch ( \RuntimeException $e ) {
			self::log(
				'transport error',
				[
					'url'   => $url,
					'error' => $e->getMessage(),
				]
			);
			return [
				'ok'      => false,
				'message' => $e->getMessage(),
				'code'    => 'TRANSPORT',
				'http'    => 0,
				'data'    => [],
			];
		}

		$decoded = $resp->json();
		$code    = (string) ( $decoded['code'] ?? '' );
		$status  = (string) ( $decoded['status'] ?? '' );

		if ( ! $resp->ok() ) {
			self::log(
				'http error',
				[
					'url'    => $url,
					'status' => $resp->status,
					'code'   => $code,
				]
			);
			return [
				'ok'      => false,
				'message' => (string) ( $decoded['msg'] ?? $decoded['message'] ?? sprintf( 'HTTP %d', $resp->status ) ),
				'code'    => '' !== $code ? $code : (string) $resp->status,
				'http'    => (int) $resp->status,
				'data'    => $decoded,
			];
		}

		if ( '' !== $code && '0' !== $code && 'SUCCESS' !== strtoupper( $code ) ) {
			self::log(
				'business error',
				[
					'url'    => $url,
					'code'   => $code,
					'status' => $status,
				]
			);
			return [
				'ok'      => false,
				'message' => (string) ( $decoded['msg'] ?? $decoded['message'] ?? __( 'The Shopline Payments API call failed', 'moksa-for-woocommerce' ) ),
				'code'    => $code,
				'http'    => (int) $resp->status,
				'data'    => $decoded,
			];
		}

		return [
			'ok'      => true,
			'message' => 'OK',
			'code'    => $code,
			'http'    => (int) $resp->status,
			'data'    => $decoded,
		];
	}

	private static function log( string $message, array $context = [] ): void {
		Helper::log( $message, $context );
	}
}
