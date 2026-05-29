<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Http;

use MoksaWeb\Mowc\Crypto\Sha;

defined( 'ABSPATH' ) || exit;

final class WebhookVerifier {

	public static function signature_matches( string $expected, string $actual ): bool {
		return Sha::equals( $expected, $actual );
	}

	public static function source_ip_allowed( array $allow_list, ?string $remote_ip = null ): bool {
		$remote_ip ??= self::client_ip();
		if ( '' === $remote_ip ) {
			return false;
		}
		foreach ( $allow_list as $allowed ) {
			if ( hash_equals( $allowed, $remote_ip ) ) {
				return true;
			}
		}
		return false;
	}

	private static function client_ip(): string {
		$server = $_SERVER;
		if ( isset( $server['REMOTE_ADDR'] ) ) {
			$ip = (string) $server['REMOTE_ADDR'];
			return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		}
		return '';
	}
}
