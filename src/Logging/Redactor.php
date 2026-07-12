<?php

declare( strict_types=1 );

namespace Moksafowo\Logging;

defined( 'ABSPATH' ) || exit;

final class Redactor {

	private const SECRET_KEYS = [
		'hashkey',
		'hashiv',
		'hash_key',
		'hash_iv',
		'secret',
		'channel_secret',
		'merchant_pass',
		'pass_code',
		'passcode',
		'api_key',
		'access_token',
		'pcpay-token',
		'pcpay_token',
		'authorization',
		'private_key',
		'client_secret',
		'cardno',
		'card_no',
		'card_number',
		'cvv',
		'cvc',
		'pan',
		'check_mac_value',
		'checkmacvalue',
		'trade_sha',
		'tradesha',
		'trade_info',
		'tradeinfo',
		'check_code',
		'checkcode',
		'prime',
		'partner_key',
		'card_token',
		'card_key',
		'signature',
		'x-tappay-signature',
		'sign',
		'hmac',
	];

	private const PII_KEYS = [
		'email',
		'phone',
		'mobile',
		'tel',
		'address',
		'name',
		'ubn',
		'id_number',
		'last4',
		'last5',
		'last_four',
		'bin_code',
		'bank_account',
		'virtual_account',
		'buyer_account',
		'atm_no',
		'atmno',
		'pan_no4',
		'cvs_store_id',
		'cvs_store_name',
		'cvs_address',
		'cvs_telephone',
		'store_id',
		'store_name',
		'store_address',
	];

	public static function redact( array $context ): array {
		$out = [];
		foreach ( $context as $key => $value ) {
			$lower = strtolower( (string) $key );

			if ( self::matches( $lower, self::SECRET_KEYS ) ) {
				$out[ $key ] = '[REDACTED]';
				continue;
			}

			if ( is_array( $value ) ) {
				$out[ $key ] = self::redact( $value );
				continue;
			}

			if ( self::matches( $lower, self::PII_KEYS ) && is_string( $value ) ) {
				$out[ $key ] = self::mask( $value );
				continue;
			}

			$out[ $key ] = $value;
		}
		return $out;
	}

	private static function matches( string $key, array $patterns ): bool {
		foreach ( $patterns as $needle ) {
			if ( str_contains( $key, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function mask( string $value ): string {
		$len = mb_strlen( $value );
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		$visible = min( 4, max( 2, intdiv( $len, 4 ) ) );
		return str_repeat( '*', $len - $visible ) . mb_substr( $value, -$visible );
	}

	public static function redact_string( string $message ): string {
		$secret = implode( '|', array_map( 'preg_quote', self::SECRET_KEYS ) );
		$pii    = implode( '|', array_map( 'preg_quote', self::PII_KEYS ) );

		$message = preg_replace_callback(
			'/\[\s*(?P<k>[a-z0-9_\-]+)\s*\]\s*=>\s*(?P<v>[^\r\n\[\)]+?)(?=\s*(?:\[|\)|$|\r|\n))/i',
			static function ( array $m ) use ( $secret, $pii ): string {
				$key = strtolower( $m['k'] );
				if ( preg_match( "/(?:{$secret})/i", $key ) ) {
					return '[' . $m['k'] . '] => [REDACTED]';
				}
				if ( preg_match( "/(?:{$pii})/i", $key ) ) {
					return '[' . $m['k'] . '] => ' . self::mask( trim( $m['v'] ) );
				}
				return $m[0];
			},
			$message
		) ?? $message;

		$message = preg_replace_callback(
			'/(?P<k>[a-z][a-z0-9_\-]*)=(?P<v>[^&\s]+)/i',
			static function ( array $m ) use ( $secret, $pii ): string {
				$key = strtolower( $m['k'] );
				if ( preg_match( "/(?:{$secret})/i", $key ) ) {
					return $m['k'] . '=[REDACTED]';
				}
				if ( preg_match( "/(?:{$pii})/i", $key ) ) {
					return $m['k'] . '=' . self::mask( $m['v'] );
				}
				return $m[0];
			},
			$message
		) ?? $message;

		$message = preg_replace_callback(
			'/"(?P<k>[a-z0-9_\-]+)"\s*:\s*"(?P<v>[^"]*)"/i',
			static function ( array $m ) use ( $secret, $pii ): string {
				$key = strtolower( $m['k'] );
				if ( preg_match( "/(?:{$secret})/i", $key ) ) {
					return '"' . $m['k'] . '":"[REDACTED]"';
				}
				if ( preg_match( "/(?:{$pii})/i", $key ) ) {
					return '"' . $m['k'] . '":"' . self::mask( $m['v'] ) . '"';
				}
				return $m[0];
			},
			$message
		) ?? $message;

		return $message;
	}
}
