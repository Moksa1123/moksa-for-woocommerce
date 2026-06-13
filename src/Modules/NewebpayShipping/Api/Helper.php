<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\NewebpayShipping\Api;

use MoksaWeb\Mowc\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;


final class Helper extends AbstractCredentialHelper {

	protected static function option_prefix(): string {
		return 'moksafowo_newebpay_shipping';
	}

	protected static function log_source(): string {
		return 'newebpay-shipping';
	}

	public static function is_sandbox(): bool {
		$raw = get_option( 'moksafowo_newebpay_shipping_sandbox_enabled', null );
		if ( null === $raw ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
			return (bool) apply_filters( 'moksafowo_newebpay_shipping_sandbox_fallback', false );
		}
		return 'yes' === $raw;
	}

	public static function merchant_id(): string {
		$key = self::is_sandbox() ? 'moksafowo_newebpay_shipping_sandbox_merchant_id' : 'moksafowo_newebpay_shipping_merchant_id';
		$val = (string) get_option( $key, '' );
		if ( '' !== $val ) {
			return $val;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		return (string) apply_filters( 'moksafowo_newebpay_shipping_merchant_id_fallback', '' );
	}

	public static function hash_key(): string {
		$key = self::is_sandbox() ? 'moksafowo_newebpay_shipping_sandbox_hash_key' : 'moksafowo_newebpay_shipping_hash_key';
		$val = (string) get_option( $key, '' );
		if ( '' !== $val ) {
			return $val;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		return (string) apply_filters( 'moksafowo_newebpay_shipping_hash_key_fallback', '' );
	}

	public static function hash_iv(): string {
		$key = self::is_sandbox() ? 'moksafowo_newebpay_shipping_sandbox_hash_iv' : 'moksafowo_newebpay_shipping_hash_iv';
		$val = (string) get_option( $key, '' );
		if ( '' !== $val ) {
			return $val;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		return (string) apply_filters( 'moksafowo_newebpay_shipping_hash_iv_fallback', '' );
	}

	public static function verify_trade_sha( string $trade_info, string $trade_sha ): bool {
		$expected = strtoupper( hash( 'sha256', 'HashKey=' . self::hash_key() . '&' . $trade_info . '&HashIV=' . self::hash_iv() ) );
		return hash_equals( $expected, strtoupper( $trade_sha ) );
	}

	public static function decrypt_trade_info( string $trade_info ): ?array {
		$bin = @hex2bin( $trade_info );
		if ( false === $bin ) {
			return null;
		}
		$plain = openssl_decrypt( $bin, 'aes-256-cbc', self::hash_key(), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, self::hash_iv() );
		if ( false === $plain ) {
			return null;
		}
		$pad = ord( substr( $plain, -1 ) );
		if ( $pad > 0 && $pad <= 16 ) {
			$plain = substr( $plain, 0, -$pad );
		}
		$json = json_decode( $plain, true );
		return is_array( $json ) ? $json : null;
	}

	
	public static function parse_order_id( string $merchant_order_no ): int {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		$external = apply_filters( 'moksafowo_newebpay_shipping_parse_order_id', null, $merchant_order_no );
		if ( null !== $external ) {
			return (int) $external;
		}
		// Fallback parser — NewebPay merchant_order_no 內含 6-digit 訂單 id
		if ( preg_match( '/(\d{6})R[a-f0-9]+/i', $merchant_order_no, $m ) ) {
			$id = (int) ltrim( $m[1], '0' );
			return $id > 0 ? $id : 0;
		}
		return 0;
	}
}
