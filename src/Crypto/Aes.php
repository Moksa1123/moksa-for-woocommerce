<?php

declare( strict_types=1 );

namespace Moksafowo\Crypto;

defined( 'ABSPATH' ) || exit;

final class Aes {

	public static function encrypt_cbc_hex( string $plain, string $key, string $iv ): string {
		// AES 的 block size 一律 16 bytes(AES-256 指金鑰 256-bit,block 仍 128-bit)。
		// 原本 pad 到 32 會產生 >16 的 PKCS7 padding 量,NewebPay / ezPay 伺服器用標準
		// 16-byte PKCS7 解 padding 時(視 query 長度)會判定無效 → 回「加密資料有誤」。
		// 官方範例即 openssl_encrypt(..., OPENSSL_RAW_DATA, ...)(= OpenSSL 內建 16-byte PKCS7)。
		$padded = self::pkcs7_pad( $plain, 16 );
		$bin    = openssl_encrypt(
			$padded,
			'AES-256-CBC',
			$key,
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
			$iv
		);
		if ( false === $bin ) {
			throw new \RuntimeException( 'openssl_encrypt failed' );
		}
		return bin2hex( $bin );
	}

	public static function decrypt_cbc_hex( string $hex, string $key, string $iv ): string {
		// 先驗證再 hex2bin — 直接餵非法字串會先噴 PHP warning 才回 false。
		if ( '' === $hex || 0 !== strlen( $hex ) % 2 || ! ctype_xdigit( $hex ) ) {
			throw new \RuntimeException( 'invalid hex input' );
		}
		$bin = hex2bin( $hex );
		if ( false === $bin ) {
			throw new \RuntimeException( 'invalid hex input' );
		}
		$plain = openssl_decrypt(
			$bin,
			'AES-256-CBC',
			$key,
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
			$iv
		);
		if ( false === $plain ) {
			throw new \RuntimeException( 'openssl_decrypt failed' );
		}
		return self::pkcs7_unpad( $plain );
	}


	public static function encrypt_gcm( string $plain, string $key, string $iv, string $aad = '' ): array {
		$tag = '';
		$bin = openssl_encrypt(
			$plain,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$aad,
			16
		);
		if ( false === $bin ) {
			throw new \RuntimeException( 'openssl_encrypt (gcm) failed' );
		}
		return [ base64_encode( $bin ), base64_encode( $tag ) ];
	}

	public static function decrypt_gcm( string $ciphertext_b64, string $tag_b64, string $key, string $iv, string $aad = '' ): string {
		$bin = base64_decode( $ciphertext_b64, true );
		$tag = base64_decode( $tag_b64, true );
		if ( false === $bin || false === $tag ) {
			throw new \RuntimeException( 'invalid base64 input' );
		}
		$plain = openssl_decrypt(
			$bin,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$aad
		);
		if ( false === $plain ) {
			throw new \RuntimeException( 'openssl_decrypt (gcm) failed' );
		}
		return $plain;
	}

	private static function pkcs7_pad( string $data, int $block_size ): string {
		$pad_len = $block_size - ( strlen( $data ) % $block_size );
		return $data . str_repeat( chr( $pad_len ), $pad_len );
	}

	private static function pkcs7_unpad( string $data ): string {
		$len = strlen( $data );
		if ( 0 === $len ) {
			return '';
		}
		$pad_len = ord( $data[ $len - 1 ] );
		if ( $pad_len < 1 || $pad_len > 32 ) {
			return $data;
		}
		return substr( $data, 0, $len - $pad_len );
	}
}
