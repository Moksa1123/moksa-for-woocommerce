<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Crypto;

defined( 'ABSPATH' ) || exit;

final class Vault {

	private const PREFIX     = 'MOWPv1:';
	private const IV_LEN     = 12;
	private const TAG_LEN    = 16;

	private static array $wrapped = [];

	// ciphertext → plaintext memoize — 同 request 內同 option 多次 get_option 不重 decrypt
	// （Payuni hashkey/hashiv 在 cart→thankyou 流程被讀數十次）
	private static array $decrypted_cache = [];

	public static function wrap_option( string $option_name ): void {
		if ( isset( self::$wrapped[ $option_name ] ) ) {
			return;
		}
		self::$wrapped[ $option_name ] = true;

		add_filter( "pre_update_option_{$option_name}", [ self::class, 'on_update' ], 10, 2 );
		add_filter( "option_{$option_name}", [ self::class, 'on_read' ], 10, 1 );
	}

	public static function on_update( $value, $old_value = null ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		if ( self::looks_encrypted( $value ) ) {
			return $value;
		}
		try {
			return self::encrypt( $value );
		} catch ( \RuntimeException $e ) {
			return $value;
		}
	}

	public static function on_read( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		if ( ! self::looks_encrypted( $value ) ) {
			return $value;
		}
		if ( isset( self::$decrypted_cache[ $value ] ) ) {
			return self::$decrypted_cache[ $value ];
		}
		try {
			$pt = self::decrypt( $value );
			self::$decrypted_cache[ $value ] = $pt;
			return $pt;
		} catch ( \RuntimeException $e ) {
			return $value;
		}
	}

	public static function encrypt( string $plaintext ): string {
		$key = self::master_key();
		$iv  = random_bytes( self::IV_LEN );
		$tag = '';
		$ct  = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);
		if ( false === $ct ) {
			throw new \RuntimeException( 'Vault encrypt failed.' );
		}
		return self::PREFIX . base64_encode( $iv . $tag . $ct );
	}

	public static function decrypt( string $encoded ): string {
		if ( ! self::looks_encrypted( $encoded ) ) {
			throw new \RuntimeException( 'Vault: not an encrypted payload.' );
		}
		$blob = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true );
		if ( false === $blob || strlen( $blob ) < self::IV_LEN + self::TAG_LEN + 1 ) {
			throw new \RuntimeException( 'Vault: payload too short.' );
		}
		$iv  = substr( $blob, 0, self::IV_LEN );
		$tag = substr( $blob, self::IV_LEN, self::TAG_LEN );
		$ct  = substr( $blob, self::IV_LEN + self::TAG_LEN );
		$pt  = openssl_decrypt(
			$ct,
			'aes-256-gcm',
			self::master_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);
		if ( false === $pt ) {
			throw new \RuntimeException( 'Vault: decrypt failed (tampered or wrong key).' );
		}
		return $pt;
	}

	private static function looks_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	private static function master_key(): string {
		// MOKSAFOWO_VAULT_KEY is the current name; MOWP_VAULT_KEY kept as fallback for sites that set the legacy const.
		$source = defined( 'MOKSAFOWO_VAULT_KEY' ) ? (string) MOKSAFOWO_VAULT_KEY
			: ( defined( 'MOWP_VAULT_KEY' ) ? (string) MOWP_VAULT_KEY
			: ( defined( 'AUTH_KEY' )       ? (string) AUTH_KEY
			: ( defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY
			: '' ) ) );
		if ( '' === $source ) {
			throw new \RuntimeException( 'Vault: no master key (define AUTH_KEY in wp-config.php).' );
		}
		return hash( 'sha256', $source, true );
	}
}
