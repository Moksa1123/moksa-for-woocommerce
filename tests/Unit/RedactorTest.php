<?php
declare( strict_types=1 );

namespace Moksafowo\Tests\Unit;

use Moksafowo\Logging\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase {

	public function test_provider_signature_keys_redacted(): void {
		$redacted = Redactor::redact( [
			'check_mac_value'    => 'ECPAY_SIG_ABC',
			'tradesha'           => 'NEWEBPAY_SIG',
			'trade_info'         => 'AES_BLOB',
			'check_code'         => 'EZPAY_SIG',
			'pass_code'          => 'PAYNOW_SIG',
			'partner_key'        => 'TAPPAY_SECRET',
			'prime'              => 'tappay_prime',
			'card_token'         => 'PAYUNI_TOKEN',
			'x-tappay-signature' => 'HMAC',
			'normal_field'       => 'visible',
		] );

		foreach ( [ 'check_mac_value', 'tradesha', 'trade_info', 'check_code', 'pass_code', 'partner_key', 'prime', 'card_token', 'x-tappay-signature' ] as $k ) {
			self::assertSame( '[REDACTED]', $redacted[ $k ], "Key $k should be REDACTED" );
		}
		self::assertSame( 'visible', $redacted['normal_field'] );
	}

	public function test_pii_keys_masked_not_redacted(): void {
		$redacted = Redactor::redact( [
			'card_last4'      => '4242',
			'virtual_account' => '01234567890123',
			'cvs_store_id'    => '987654',
			'phone'           => '0912345678',
		] );

		// PII 走 mask（顯示後幾碼），不是完全 [REDACTED]
		foreach ( $redacted as $k => $v ) {
			self::assertStringNotContainsString( '01234567890123', $v, "PII $k should not be visible" );
			self::assertNotSame( '[REDACTED]', $v, "PII $k should be masked not REDACTED" );
		}
	}

	public function test_nested_arrays_recursed(): void {
		$redacted = Redactor::redact( [
			'card_info' => [
				'last_four'   => '4242',
				'partner_key' => 'SECRET_INSIDE',
				'public'      => 'visible',
			],
		] );

		self::assertIsArray( $redacted['card_info'] );
		self::assertSame( '[REDACTED]', $redacted['card_info']['partner_key'] );
		self::assertSame( 'visible', $redacted['card_info']['public'] );
	}

	public function test_case_insensitive_match(): void {
		$redacted = Redactor::redact( [
			'CheckMacValue' => 'ECPay-style camelCase',
			'TradeSha'      => 'Newebpay-style PascalCase',
		] );

		self::assertSame( '[REDACTED]', $redacted['CheckMacValue'] );
		self::assertSame( '[REDACTED]', $redacted['TradeSha'] );
	}
}
