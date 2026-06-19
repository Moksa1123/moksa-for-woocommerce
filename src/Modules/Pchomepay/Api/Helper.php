<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Pchomepay\Api;

use MoksaWeb\Mowc\Http\Request;
use MoksaWeb\Mowc\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	// PChomePay 沒公開測試帳號 — 商家須自行向支付連申請沙箱憑證。
	public const BASE_SANDBOX = 'https://sandbox-api.pchomepay.com.tw';
	public const BASE_PROD    = 'https://api.pchomepay.com.tw';

	// Notify 來源 IP（合作方必須加白名單）。
	public const NOTIFY_IP = '113.196.231.190';

	public const PATH_TOKEN   = '/v1/token';
	public const PATH_PAYMENT = '/v1/payment';
	public const PATH_REFUND  = '/v1/refund';

	private const TOKEN_TTL = 28800; // 8h.

	protected static function option_prefix(): string {
		return 'moksafowo_pchomepay';
	}

	protected static function log_source(): string {
		return 'pchomepay-payment';
	}

	public static function is_sandbox(): bool {
		return 'yes' === get_option( 'moksafowo_pchomepay_sandbox_enabled', 'no' );
	}

	public static function app_id(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_pchomepay_sandbox_app_id', '' );
		}
		return (string) get_option( 'moksafowo_pchomepay_app_id', '' );
	}

	public static function secret(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_pchomepay_sandbox_secret', '' );
		}
		return (string) get_option( 'moksafowo_pchomepay_secret', '' );
	}

	public static function has_credentials(): bool {
		return '' !== self::app_id() && '' !== self::secret();
	}

	public static function base_url(): string {
		return self::is_sandbox() ? self::BASE_SANDBOX : self::BASE_PROD;
	}

	public static function token_url(): string {
		return self::base_url() . self::PATH_TOKEN;
	}

	public static function payment_url(): string {
		return self::base_url() . self::PATH_PAYMENT;
	}

	public static function refund_url(): string {
		return self::base_url() . self::PATH_REFUND;
	}

	private static function token_transient_key(): string {
		return 'mowp_pchomepay_token_' . md5( self::app_id() );
	}

	public static function get_token( bool $force = false ): string {
		$key = self::token_transient_key();
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$app_id = self::app_id();
		$secret = self::secret();
		if ( '' === $app_id || '' === $secret ) {
			self::log( 'token request skipped — credentials unset' );
			return '';
		}

		try {
			$resp = Request::post(
				self::token_url(),
				[],
				[ 'Authorization' => 'Basic ' . base64_encode( $app_id . ':' . $secret ) ],
				'json'
			);
		} catch ( \RuntimeException $e ) {
			self::log( 'token request transport error', [ 'error' => $e->getMessage() ] );
			return '';
		}

		$body  = $resp->json();
		$token = (string) ( $body['token'] ?? '' );
		if ( '' === $token ) {
			self::log(
				'token request failed',
				[
					'status' => $resp->status,
					'code'   => (string) ( $body['code'] ?? '' ),
				]
			);
			return '';
		}

		$ttl = (int) ( $body['expired_in'] ?? self::TOKEN_TTL );
		$ttl = $ttl > 60 ? $ttl - 60 : self::TOKEN_TTL - 60;
		set_transient( $key, $token, $ttl );
		self::log( 'token acquired', [ 'ttl' => $ttl ] );
		return $token;
	}

	public static function bust_token(): void {
		delete_transient( self::token_transient_key() );
	}


	public static function api_post( string $url, array $body, bool $retried = false ): array {
		$token = self::get_token( $retried );
		if ( '' === $token ) {
			return [
				'ok'      => false,
				'message' => __( '無法取得支付連 token，請確認 APP ID / Secret。', 'mo-ectools' ),
				'code'    => 'NO_TOKEN',
				'data'    => [],
			];
		}

		try {
			$resp = Request::post( $url, $body, [ 'pcpay-token' => $token ], 'json' );
		} catch ( \RuntimeException $e ) {
			self::log(
				'api transport error',
				[
					'url'   => $url,
					'error' => $e->getMessage(),
				]
			);
			return [
				'ok'      => false,
				'message' => $e->getMessage(),
				'code'    => 'TRANSPORT',
				'data'    => [],
			];
		}

		$decoded = $resp->json();
		$code    = (string) ( $decoded['code'] ?? ( $decoded['status'] ?? '' ) );

		// Token 錯誤 / 逾期 → 清 transient 重取一次。
		if ( in_array( $code, [ '10003', '10004' ], true ) && ! $retried ) {
			self::bust_token();
			return self::api_post( $url, $body, true );
		}

		// 成功回應沒有 code 欄位（直接回 payload）；失敗時帶 code + message。
		$is_error = isset( $decoded['code'] ) && '' !== (string) $decoded['code'];
		if ( $is_error ) {
			self::log(
				'api error',
				[
					'url'     => $url,
					'code'    => $code,
					'message' => (string) ( $decoded['message'] ?? '' ),
				]
			);
			return [
				'ok'      => false,
				'message' => (string) ( $decoded['message'] ?? __( '支付連 API 失敗', 'mo-ectools' ) ),
				'code'    => $code,
				'data'    => $decoded,
			];
		}

		if ( ! $resp->ok() ) {
			return [
				'ok'      => false,
				'message' => sprintf( 'HTTP %d', $resp->status ),
				'code'    => (string) $resp->status,
				'data'    => $decoded,
			];
		}

		return [
			'ok'      => true,
			'message' => 'OK',
			'code'    => '',
			'data'    => $decoded,
		];
	}

	public static function generate_order_id( int $order_id, bool $retry = false ): string {
		$base = (string) $order_id;
		if ( $retry ) {
			$base .= 'T' . time();
		}
		return preg_replace( '/[^A-Za-z0-9_-]/', '', $base ) ?? $base;
	}

	public static function parse_order_id( string $pchome_order_id ): ?int {
		if ( ! preg_match( '/^(\d+)/', $pchome_order_id, $m ) ) {
			return null;
		}
		$id = (int) $m[1];
		return $id > 0 ? $id : null;
	}
}
