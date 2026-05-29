<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Tests\Unit;

use MoksaWeb\Mowc\Crypto\Aes;
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
		// 32-byte（剛好整塊）— PKCS#7 應補一整塊（32 bytes of 0x20）
		$plain  = str_repeat( 'A', 32 );
		$cipher = Aes::encrypt_cbc_hex( $plain, self::KEY_32, self::IV_16 );
		self::assertSame( $plain, Aes::decrypt_cbc_hex( $cipher, self::KEY_32, self::IV_16 ) );
		// 結果應是 64 bytes (128 hex chars) = 2 個 32-byte block（明文 1 + padding 1）
		self::assertSame( 128, strlen( $cipher ) );
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
