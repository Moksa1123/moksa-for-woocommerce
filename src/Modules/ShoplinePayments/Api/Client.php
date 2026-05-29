<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\ShoplinePayments\Api;

use MoksaWeb\Mowc\Http\Request;

defined( 'ABSPATH' ) || exit;

final class Client {

	
	public static function create_session( array $body ): array {
		return self::post( Helper::session_create_url(), $body );
	}

	
	public static function query_session( string $session_id ): array {
		return self::post( Helper::session_query_url(), [ 'sessionId' => $session_id ] );
	}

	
	public static function create_refund( array $body, string $idempotent_key ): array {
		return self::post( Helper::refund_create_url(), $body, $idempotent_key );
	}

	
	private static function post( string $url, array $body, string $idempotent_key = '' ): array {
		$merchant_id = Helper::merchant_id();
		$api_key     = Helper::api_key();
		if ( '' === $merchant_id || '' === $api_key ) {
			self::log( 'request skipped — credentials unset', [ 'url' => $url ] );
			return [ 'ok' => false, 'message' => __( '尚未設定 Shopline Payments 商家憑證。', 'mo-ectools' ), 'code' => 'NO_CREDENTIALS', 'data' => [] ];
		}

		$headers = [
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
			self::log( 'transport error', [ 'url' => $url, 'error' => $e->getMessage() ] );
			return [ 'ok' => false, 'message' => $e->getMessage(), 'code' => 'TRANSPORT', 'data' => [] ];
		}

		$decoded = $resp->json();
		$code    = (string) ( $decoded['code'] ?? '' );
		$status  = (string) ( $decoded['status'] ?? '' );

		// 傳輸 / 額度錯誤（非 2xx）— 帶 code + msg。
		if ( ! $resp->ok() ) {
			self::log( 'http error', [ 'url' => $url, 'status' => $resp->status, 'code' => $code ] );
			return [
				'ok'      => false,
				'message' => (string) ( $decoded['msg'] ?? $decoded['message'] ?? sprintf( 'HTTP %d', $resp->status ) ),
				'code'    => '' !== $code ? $code : (string) $resp->status,
				'data'    => $decoded,
			];
		}

		// 200 但業務失敗 — SLP 帶 code（非空 = 錯誤）。
		if ( '' !== $code && '0' !== $code && 'SUCCESS' !== strtoupper( $code ) ) {
			self::log( 'business error', [ 'url' => $url, 'code' => $code, 'status' => $status ] );
			return [
				'ok'      => false,
				'message' => (string) ( $decoded['msg'] ?? $decoded['message'] ?? __( 'Shopline Payments API 失敗', 'mo-ectools' ) ),
				'code'    => $code,
				'data'    => $decoded,
			];
		}

		return [ 'ok' => true, 'message' => 'OK', 'code' => $code, 'data' => $decoded ];
	}

	private static function log( string $message, array $context = [] ): void {
		Helper::log( $message, $context );
	}
}
