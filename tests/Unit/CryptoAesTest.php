<?php
declare( strict_types=1 );

namespace Moksafowo\Tests\Unit;

use Moksafowo\Crypto\Aes;
use PHPUnit\Framework\TestCase;

final class CryptoAesTest extends TestCase {

	private const KEY_32 = '12345678901234567890123456789012';
	private const IV_16  = '1234567890123456';

	public function test_cbc_round_trip_hex(): void {
		$plain  = 'hello world';
		$cipher = Aes::encrypt_cbc_hex( $plain, self::KEY_32, self::IV_16 );

		// hex 結果（OPENSSL_RAW_DATA + bin2hex），全小寫 hex
		self::assertMatchesRegularExpression( '/^[0-9a-f]+$/', $cipher );
		self::assertSame( $plain, Aes::decrypt_cbc_hex( $cipher, self::KEY_32, self::IV_16 ) );
	}

	public function test_cbc_block_aligned_padding(): void {
		// AES block = 16 bytes。32-byte 明文剛好整塊 → PKCS#7 補一整塊（16 bytes of 0x10）。
		$plain  = str_repeat( 'A', 32 );
		$cipher = Aes::encrypt_cbc_hex( $plain, self::KEY_32, self::IV_16 );
		self::assertSame( $plain, Aes::decrypt_cbc_hex( $cipher, self::KEY_32, self::IV_16 ) );
		// 48 bytes (96 hex chars) = 3 個 16-byte block（明文 2 + padding 1）
		self::assertSame( 96, strlen( $cipher ) );
	}

	public function test_cbc_throws_on_bad_hex(): void {
		$this->expectException( \RuntimeException::class );
		Aes::decrypt_cbc_hex( 'not-hex-data!', self::KEY_32, self::IV_16 );
	}

	public function test_gcm_round_trip(): void {
		$plain  = 'sensitive payload';
		$aad    = 'channel=mowp';
		[ $ct, $tag ] = Aes::encrypt_gcm( $plain, self::KEY_32, '123456789012', $aad );

		self::assertNotEmpty( $ct );
		self::assertNotEmpty( $tag );
		self::assertSame( $plain, Aes::decrypt_gcm( $ct, $tag, self::KEY_32, '123456789012', $aad ) );
	}

	public function test_gcm_rejects_aad_tampering(): void {
		$plain = 'sensitive';
		[ $ct, $tag ] = Aes::encrypt_gcm( $plain, self::KEY_32, '123456789012', 'aad-1' );

		$this->expectException( \RuntimeException::class );
		Aes::decrypt_gcm( $ct, $tag, self::KEY_32, '123456789012', 'aad-2' );
	}
}
