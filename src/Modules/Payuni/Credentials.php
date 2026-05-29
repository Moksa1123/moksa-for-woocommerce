<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Payuni;

defined( 'ABSPATH' ) || exit;

// 讀兼容 facade — 優先 `mo_payuni_*`，fallback legacy `payuni_payment_*`
final class Credentials {

	public static function test_mode_enabled(): bool {
		$new = get_option( 'mo_payuni_payment_testmode_enabled' );
		if ( false !== $new ) {
			return (bool) wc_string_to_bool( (string) $new );
		}
		return (bool) wc_string_to_bool( (string) get_option( 'payuni_payment_testmode_enabled', 'no' ) );
	}

	public static function hashkey(): string {
		$test = self::test_mode_enabled();
		return self::read_option(
			$test ? 'mo_payuni_payment_hashkey_test' : 'mo_payuni_payment_hashkey',
			$test ? 'payuni_payment_hashkey_test'    : 'payuni_payment_hashkey'
		);
	}

	public static function hashiv(): string {
		$test = self::test_mode_enabled();
		return self::read_option(
			$test ? 'mo_payuni_payment_hashiv_test' : 'mo_payuni_payment_hashiv',
			$test ? 'payuni_payment_hashiv_test'    : 'payuni_payment_hashiv'
		);
	}

	public static function merchant_id(): string {
		$test = self::test_mode_enabled();
		return self::read_option(
			$test ? 'mo_payuni_payment_merchant_id_test' : 'mo_payuni_payment_merchant_id',
			$test ? 'payuni_payment_merchant_id_test'    : 'payuni_payment_merchant_id'
		);
	}

	private static function read_option( string $new_key, string $legacy_key ): string {
		$new = get_option( $new_key );
		if ( is_string( $new ) && '' !== $new ) {
			return trim( $new );
		}
		return trim( (string) get_option( $legacy_key, '' ) );
	}
}
