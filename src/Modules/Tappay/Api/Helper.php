<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Tappay\Api;

use MoksaWeb\Mowc\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	// API host（pay-by-prime / refund / query）。
	public const BASE_SANDBOX = 'https://sandbox.tappaysdk.com';
	public const BASE_PROD    = 'https://prod.tappaysdk.com';

	// 前端 tpdirect SDK（hosted-only，不可 vendoring）。
	public const SDK_URL = 'https://js.tappaysdk.com/sdk/tpdirect/v5';

	public const PATH_PAY_BY_PRIME = '/tpc/payment/pay-by-prime';
	public const PATH_REFUND       = '/tpc/transaction/refund';
	public const PATH_QUERY        = '/tpc/transaction/query';

	public const SANDBOX_APP_ID      = '11327';
	public const SANDBOX_APP_KEY     = 'app_whdEWBH8e8Lzy4N6BysVRRMILYORF6UxXbiOFsICkz0J9j1C0JUlCHv1tVJC';
	public const SANDBOX_PARTNER_KEY = 'partner_6ID1DoDlaPrfHw6HBZsULfTYtDmWs0q0ZZGKMBpp4YICWBxgK97eK3RM';
	public const SANDBOX_MERCHANT_ID = 'GlobalTesting_CTBC';

	protected static function option_prefix(): string {
		return 'moksafowo_tappay';
	}

	protected static function log_source(): string {
		return 'tappay-payment';
	}

	public static function is_sandbox(): bool {
		return 'yes' === get_option( 'moksafowo_tappay_sandbox_enabled', 'yes' );
	}

	private static function sandbox_value( string $option_suffix, string $const_define, string $fallback ): string {
		$opt = trim( (string) get_option( 'moksafowo_tappay_sandbox_' . $option_suffix, '' ) );
		if ( '' !== $opt ) {
			return $opt;
		}
		if ( defined( $const_define ) && '' !== (string) constant( $const_define ) ) {
			return (string) constant( $const_define );
		}
		return $fallback;
	}

	public static function app_id(): string {
		if ( self::is_sandbox() ) {
			return self::sandbox_value( 'app_id', 'MO_TAPPAY_SANDBOX_APP_ID', self::SANDBOX_APP_ID );
		}
		return (string) get_option( 'moksafowo_tappay_app_id', '' );
	}

	public static function app_key(): string {
		if ( self::is_sandbox() ) {
			return self::sandbox_value( 'app_key', 'MO_TAPPAY_SANDBOX_APP_KEY', self::SANDBOX_APP_KEY );
		}
		return (string) get_option( 'moksafowo_tappay_app_key', '' );
	}

	public static function partner_key(): string {
		if ( self::is_sandbox() ) {
			return self::sandbox_value( 'partner_key', 'MO_TAPPAY_SANDBOX_PARTNER_KEY', self::SANDBOX_PARTNER_KEY );
		}
		return (string) get_option( 'moksafowo_tappay_partner_key', '' );
	}

	public static function merchant_id(): string {
		if ( self::is_sandbox() ) {
			return self::sandbox_value( 'merchant_id', 'MO_TAPPAY_SANDBOX_MERCHANT_ID', self::SANDBOX_MERCHANT_ID );
		}
		return (string) get_option( 'moksafowo_tappay_merchant_id', '' );
	}

	public static function notify_secret(): string {
		if ( self::is_sandbox() ) {
			$opt = trim( (string) get_option( 'moksafowo_tappay_sandbox_notify_secret', '' ) );
		} else {
			$opt = trim( (string) get_option( 'moksafowo_tappay_notify_secret', '' ) );
		}
		return '' !== $opt ? $opt : self::partner_key();
	}

	public static function three_domain_secure_enabled(): bool {
		return 'yes' === get_option( 'moksafowo_tappay_3ds_enabled', 'yes' );
	}

	public static function sdk_env(): string {
		return self::is_sandbox() ? 'sandbox' : 'production';
	}

	public static function has_credentials(): bool {
		return '' !== self::app_id()
			&& '' !== self::app_key()
			&& '' !== self::partner_key()
			&& '' !== self::merchant_id();
	}

	public static function base_url(): string {
		return self::is_sandbox() ? self::BASE_SANDBOX : self::BASE_PROD;
	}

	public static function pay_by_prime_url(): string {
		return self::base_url() . self::PATH_PAY_BY_PRIME;
	}

	public static function refund_url(): string {
		return self::base_url() . self::PATH_REFUND;
	}

	public static function query_url(): string {
		return self::base_url() . self::PATH_QUERY;
	}

	public static function build_order_number( \WC_Order $order, bool $retry = false ): string {
		$base = (string) $order->get_order_number();
		if ( $retry ) {
			$base .= 'T' . time();
		}
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', $base ) ?? (string) $order->get_id();
	}

	public static function parse_order_id( string $order_number ): ?int {
		if ( ! preg_match( '/^[#]?(\d+)/', $order_number, $m ) ) {
			return null;
		}
		$id = (int) $m[1];
		return $id > 0 ? $id : null;
	}

	public static function verify_notify_signature( string $raw_body, string $signature ): bool {
		$secret = self::notify_secret();
		if ( '' === $secret || '' === $signature ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $raw_body, $secret );
		// TapPay 簽章為 lowercase hex；大小寫不敏感比對前先 normalize。
		return hash_equals( $expected, strtolower( trim( $signature ) ) );
	}
}
