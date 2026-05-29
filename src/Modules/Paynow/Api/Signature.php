<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Paynow\Api;

defined( 'ABSPATH' ) || exit;

final class Signature {

	public static function make( string ...$parts ): string {
		return strtoupper( sha1( implode( '', $parts ) ) );
	}

	public static function make_pass_code2( string $pass_code, string $receiver_email ): string {
		return strtoupper( sha1( $pass_code . $receiver_email ) );
	}

	public static function verify( string $expected, string $actual ): bool {
		return hash_equals( strtoupper( $expected ), strtoupper( $actual ) );
	}
}
