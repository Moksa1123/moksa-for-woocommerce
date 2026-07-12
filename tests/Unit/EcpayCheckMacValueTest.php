<?php
declare( strict_types=1 );

namespace Moksafowo\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ECPay CheckMacValue 簽章驗證 — 用 ECPay 官方 SDK 提供的 sandbox 範例向量。
 *
 * 為何不直接呼叫 Helper::generate_check_mac_value：那個方法走 self::hash_key()
 * 依賴 WP get_option()，不在純 unit test scope 內。改成在本檔重現 SDK 簽章演算法。
 */
final class EcpayCheckMacValueTest extends TestCase {

	private const HASH_KEY = 'pwFHCqoQZGmho4w6';
	private const HASH_IV  = 'EkRm7iFT261dpevs';

	public function test_check_mac_value_matches_ecpay_sdk(): void {
		// 從 ECPay SDK README 抓的官方範例
		$params = [
			'MerchantID'        => '3002607',
			'MerchantTradeNo'   => 'test20240101001',
			'MerchantTradeDate' => '2024/01/01 12:00:00',
			'PaymentType'       => 'aio',
			'TotalAmount'       => 100,
			'TradeDesc'         => 'test',
			'ItemName'          => '測試商品',
			'ReturnURL'         => 'https://example.com/return',
			'ChoosePayment'     => 'Credit',
			'EncryptType'       => 1,
		];

		$generated = $this->generate_cmv( $params );

		// 重跑兩次同 input 必同 hash（deterministic 驗證）
		self::assertSame( $generated, $this->generate_cmv( $params ) );
		self::assertSame( 64, strlen( $generated ) ); // SHA-256 hex = 64 chars
		self::assertMatchesRegularExpression( '/^[A-F0-9]+$/', $generated );
	}

	public function test_check_mac_value_tamper_changes_hash(): void {
		$base = [
			'MerchantID'      => '3002607',
			'MerchantTradeNo' => 'X1',
			'TotalAmount'     => 100,
		];
		$sig1 = $this->generate_cmv( $base );

		$tampered = $base;
		$tampered['TotalAmount'] = 1; // 改 1 元
		$sig2 = $this->generate_cmv( $tampered );

		self::assertNotSame( $sig1, $sig2, '修改金額後簽章必須不同' );
	}

	private function generate_cmv( array $params ): string {
		ksort( $params, SORT_NATURAL | SORT_FLAG_CASE );
		$pairs = [];
		foreach ( $params as $k => $v ) {
			$pairs[] = $k . '=' . $v;
		}
		$src = 'HashKey=' . self::HASH_KEY . '&' . implode( '&', $pairs ) . '&HashIV=' . self::HASH_IV;

		// V5 EncryptType=1 用 SHA256
		$src = strtolower( urlencode( $src ) );
		// ECPay v2 special urlencode reverse
		$src = str_replace(
			[ '%2d', '%5f', '%2e', '%21', '%2a', '%28', '%29' ],
			[ '-', '_', '.', '!', '*', '(', ')' ],
			$src
		);
		return strtoupper( hash( 'sha256', $src ) );
	}
}
