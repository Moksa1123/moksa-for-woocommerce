<?php
declare( strict_types=1 );

namespace Moksafowo\Tests\Unit;

use Moksafowo\Refund\InvoiceSync;
use PHPUnit\Framework\TestCase;

/**
 * 退款發票：同一個雙月申報期才作廢，跨期改開折讓（設計 §七）。以台北時區判斷，
 * 跨期界線在奇數月 1 日 00:00（台北），不是 UTC。
 */
final class RefundInvoicePeriodTest extends TestCase {

	private static function at( string $taipei ): int {
		return ( new \DateTimeImmutable( $taipei, new \DateTimeZone( 'Asia/Taipei' ) ) )->getTimestamp();
	}

	public function test_same_bimonthly_period(): void {
		$this->assertTrue( InvoiceSync::same_period( '2026-11-01 00:00:00', self::at( '2026-12-31 23:59:59' ) ) );
		$this->assertTrue( InvoiceSync::same_period( '2026-01-15 10:00:00', self::at( '2026-02-28 12:00:00' ) ) );
	}

	public function test_next_period_is_cross_period(): void {
		$this->assertFalse( InvoiceSync::same_period( '2026-10-31 23:59:59', self::at( '2026-11-01 00:00:00' ) ) );
		$this->assertFalse( InvoiceSync::same_period( '2026-12-31 23:00:00', self::at( '2027-01-01 00:30:00' ) ) );
	}

	public function test_boundary_uses_taipei_not_utc(): void {
		// 台北 11-01 00:30 = UTC 10-31 16:30；照 UTC 算會誤判成同一期（9–10 月）。
		$this->assertFalse( InvoiceSync::same_period( '2026-10-20 12:00:00', self::at( '2026-11-01 00:30:00' ) ) );
	}

	public function test_unix_timestamp_and_empty(): void {
		$this->assertTrue( InvoiceSync::same_period( (string) self::at( '2026-11-19 16:00:00' ), self::at( '2026-11-20 09:00:00' ) ) );
		$this->assertFalse( InvoiceSync::same_period( '', self::at( '2026-11-20 09:00:00' ) ) );
		$this->assertFalse( InvoiceSync::same_period( 'not a date', self::at( '2026-11-20 09:00:00' ) ) );
	}
}
