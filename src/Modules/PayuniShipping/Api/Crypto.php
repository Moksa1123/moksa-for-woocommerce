<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Api;

use MoksaWeb\Mowc\Modules\Payuni\Credentials;

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
		$key  = Credentials::hashkey();
		$iv   = Credentials::hashiv();
		$blob = @hex2bin( $encrypt_str );
		if ( false === $blob ) {
			return [];
		}
		// strrpos 從右側找最後 ':::'（base64(tag) 不含 ':'）— 治 explode 對 ciphertext 隨機 byte 含 ':::' 的切錯 bug
		$sep_pos = strrpos( $blob, ':::' );
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
