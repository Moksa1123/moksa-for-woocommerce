<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Crypto;

defined( 'ABSPATH' ) || exit;

final class Sha {

	public static function sha1_upper( string $data ): string {
		return strtoupper( sha1( $data ) );
	}

	public static function sha256_upper( string $data ): string {
		return strtoupper( hash( 'sha256', $data ) );
	}

	public static function hmac_sha256_b64( string $data, string $key ): string {
		return base64_encode( hash_hmac( 'sha256', $data, $key, true ) );
	}

	public static function equals( string $expected, string $actual ): bool {
		return hash_equals( $expected, $actual );
	}
}
