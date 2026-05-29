<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PaynowInvoice\Api;

defined( 'ABSPATH' ) || exit;


final class Crypto {

	
	public static function encode( string $plain, string $password ): string {
		$key = '1234567890' . $password . '123456';
		// 24-byte key 為 3DES 需要長度；不足或超過會被 openssl 拒
		if ( 24 !== strlen( $key ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'PayNow TripleDES key 長度需 24 bytes，目前 %d（mem_password 長度需 8 位）', strlen( $key ) ) )
			);
		}

		// Zero-pad 至 8-byte 邊界
		$pad_len  = 8 - ( strlen( $plain ) % 8 );
		$pad_len  = 8 === $pad_len ? 0 : $pad_len;
		$padded   = $plain . str_repeat( "\0", $pad_len );

		$cipher = openssl_encrypt(
			$padded,
			'des-ede3',
			$key,
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
		);
		if ( false === $cipher ) {
			throw new \RuntimeException( 'PayNow TripleDES 加密失敗' );
		}
		return base64_encode( $cipher );
	}

	public static function decode( string $b64, string $password ): string {
		$key = '1234567890' . $password . '123456';
		$bin = base64_decode( $b64, true );
		if ( false === $bin ) {
			throw new \RuntimeException( 'PayNow TripleDES base64 decode 失敗' );
		}
		$plain = openssl_decrypt( $bin, 'des-ede3', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING );
		if ( false === $plain ) {
			throw new \RuntimeException( 'PayNow TripleDES 解密失敗' );
		}
		// 去掉尾端 zero padding
		return rtrim( $plain, "\0" );
	}
}
