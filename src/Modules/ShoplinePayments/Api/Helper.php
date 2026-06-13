<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\ShoplinePayments\Api;

use MoksaWeb\Mowc\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	// SLP 無公開測試帳號 — 商家須自行向 SLP 整合團隊申請沙箱憑證。
	public const BASE_SANDBOX = 'https://api-sandbox.shoplinepayments.com';
	public const BASE_PROD    = 'https://api.shoplinepayments.com';

	public const PATH_SESSION_CREATE = '/api/v1/trade/sessions/create';
	public const PATH_SESSION_QUERY  = '/api/v1/trade/sessions/query';
	public const PATH_REFUND_CREATE  = '/api/v1/trade/refund/create';

	protected static function option_prefix(): string {
		return 'moksafowo_shopline_payments';
	}

	protected static function log_source(): string {
		return 'shopline-payments';
	}

	public static function is_sandbox(): bool {
		return 'yes' === get_option( 'moksafowo_shopline_payments_sandbox_enabled', 'no' );
	}

	public static function merchant_id(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_shopline_payments_sandbox_merchant_id', '' );
		}
		return (string) get_option( 'moksafowo_shopline_payments_merchant_id', '' );
	}

	public static function api_key(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_shopline_payments_sandbox_api_key', '' );
		}
		return (string) get_option( 'moksafowo_shopline_payments_api_key', '' );
	}

	public static function sign_key(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_shopline_payments_sandbox_sign_key', '' );
		}
		return (string) get_option( 'moksafowo_shopline_payments_sign_key', '' );
	}

	public static function platform_id(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_shopline_payments_sandbox_platform_id', '' );
		}
		return (string) get_option( 'moksafowo_shopline_payments_platform_id', '' );
	}

	public static function allowed_payment_methods(): array {
		$raw = get_option( 'moksafowo_shopline_payments_payment_methods', [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		return array_values( array_filter( array_map( 'strval', $raw ), static fn ( string $v ): bool => '' !== $v ) );
	}

	public static function has_credentials(): bool {
		return '' !== self::merchant_id() && '' !== self::api_key();
	}

	public static function base_url(): string {
		return self::is_sandbox() ? self::BASE_SANDBOX : self::BASE_PROD;
	}

	public static function session_create_url(): string {
		return self::base_url() . self::PATH_SESSION_CREATE;
	}

	public static function session_query_url(): string {
		return self::base_url() . self::PATH_SESSION_QUERY;
	}

	public static function refund_create_url(): string {
		return self::base_url() . self::PATH_REFUND_CREATE;
	}

	public static function request_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	public static function idempotent_key( string $seed ): string {
		return substr( hash( 'sha256', $seed ), 0, 32 );
	}

	public static function build_reference_id( int $order_id, bool $retry = false ): string {
		$base = (string) $order_id;
		if ( $retry ) {
			$base .= '-' . time();
		}
		return preg_replace( '/[^A-Za-z0-9_-]/', '', $base ) ?? (string) $order_id;
	}

	public static function parse_order_id( string $reference_id ): ?int {
		if ( ! preg_match( '/^(\d+)/', $reference_id, $m ) ) {
			return null;
		}
		$id = (int) $m[1];
		return $id > 0 ? $id : null;
	}
}
