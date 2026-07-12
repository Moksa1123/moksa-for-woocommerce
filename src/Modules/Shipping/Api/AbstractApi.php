<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Api;

defined( 'ABSPATH' ) || exit;

abstract class AbstractApi {

	abstract protected function hash_key(): string;
	abstract protected function hash_iv(): string;
	abstract protected function endpoint_base(): string;

	protected function aes_method(): string {
		return 'aes-256-cbc';
	}

	public function encrypt( array $data ): string {
		$payload = http_build_query( $data );
		return strtoupper(
			bin2hex(
				openssl_encrypt(
					$this->pkcs7_pad( $payload ),
					$this->aes_method(),
					$this->hash_key(),
					OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
					$this->hash_iv()
				)
			)
		);
	}

	public function decrypt( string $encrypt_info ): array {
		$raw = openssl_decrypt(
			hex2bin( $encrypt_info ),
			$this->aes_method(),
			$this->hash_key(),
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
			$this->hash_iv()
		);
		$out = [];
		parse_str( $this->pkcs7_unpad( $raw ), $out );
		return $out;
	}

	public function hash_info( string $encrypt_info ): string {
		return strtoupper( hash( 'sha256', $this->hash_key() . $encrypt_info . $this->hash_iv() ) );
	}

	public function generate_trade_no( int $order_id, string $prefix = '' ): string {
		return $prefix . $order_id . 'TS' . random_int( 0, 9 ) . strrev( (string) time() );
	}

	public function trade_no_to_order_id( string $trade_no, string $prefix = '' ): int {
		$body   = substr( $trade_no, strlen( $prefix ) );
		$ts_pos = strrpos( $body, 'TS' );
		if ( false === $ts_pos ) {
			return 0;
		}
		return (int) substr( $body, 0, $ts_pos );
	}

	protected function pkcs7_pad( string $data, int $block_size = 16 ): string {
		$pad = $block_size - ( strlen( $data ) % $block_size );
		return $data . str_repeat( chr( $pad ), $pad );
	}

	protected function pkcs7_unpad( string $data ): string {
		$pad = ord( substr( $data, -1 ) );
		if ( $pad < 1 || $pad > 16 ) {
			return $data;
		}
		return substr( $data, 0, -$pad );
	}
}
