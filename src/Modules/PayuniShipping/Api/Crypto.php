<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Api;

use Moksafowo\Modules\Payuni\Credentials;

defined( 'ABSPATH' ) || exit;

// Wire format = bin2hex(raw_ct.':::'.b64(tag)) — PAYUNi spec，不可改
final class Crypto {

	public static function encrypt( array $encrypt_info ): string {
		$tag       = '';
		$key       = Credentials::hashkey();
		$iv        = Credentials::hashiv();
		$encrypted = openssl_encrypt( http_build_query( $encrypt_info ), 'aes-256-gcm', $key, 0, $iv, $tag );
		if ( false === $encrypted ) {
			throw new \RuntimeException( 'PAYUNi shipping AES-256-GCM encrypt failed' );
		}
		return trim( bin2hex( $encrypted . ':::' . base64_encode( $tag ) ) );
	}

	public static function decrypt( string $encrypt_str = '' ): array {
		$key = Credentials::hashkey();
		$iv  = Credentials::hashiv();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- remote hex (EncryptInfo) — malformed input returns false, validated below; @ suppresses the warning so the hex2bin return value can be validated explicitly.
		$blob = @hex2bin( $encrypt_str );
		if ( false === $blob ) {
			return [];
		}
		$sep_pos = strrpos( $blob, ':::' ); // strrpos 從右：base64(tag) 無 ':'，避免 ciphertext 含 ':::' 切錯
		if ( false === $sep_pos ) {
			return [];
		}
		$encrypt_data = substr( $blob, 0, $sep_pos );
		$tag          = base64_decode( substr( $blob, $sep_pos + 3 ), true );
		if ( false === $tag ) {
			return [];
		}
		$encrypt_info = openssl_decrypt( $encrypt_data, 'aes-256-gcm', $key, 0, $iv, $tag );
		if ( false === $encrypt_info ) {
			return [];
		}
		parse_str( $encrypt_info, $encrypt_arr );
		return $encrypt_arr;
	}

	public static function hash_info( string $encrypt_str = '' ): string {
		return strtoupper( hash( 'sha256', Credentials::hashkey() . $encrypt_str . Credentials::hashiv() ) );
	}
}
