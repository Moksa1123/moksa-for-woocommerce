<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Tests\Unit;

use MoksaWeb\Mowc\Order\Meta\Keys;
use PHPUnit\Framework\TestCase;

/**
 * Order\Meta\Keys 守則檢驗 — 所有常數必須遵守 `_mo_` 前綴（CLAUDE.md §2）。
 */
final class OrderMetaKeysTest extends TestCase {

	public function test_all_constants_prefixed_with_mo(): void {
		$reflection = new \ReflectionClass( Keys::class );
		$violations = [];
		foreach ( $reflection->getConstants() as $name => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			if ( 0 !== strpos( $value, '_mo_' ) ) {
				$violations[] = "$name = '$value'";
			}
		}
		self::assertSame( [], $violations, "違反 _mo_ 前綴的常數：\n" . implode( "\n", $violations ) );
	}

	public function test_known_keys_format(): void {
		// 抽幾條代表性常數驗格式
		self::assertSame( '_mo_ecpay_merchant_trade_no', Keys::ECPAY_MERCHANT_TRADE_NO );
		self::assertSame( '_mo_newebpay_payment_type', Keys::NEWEBPAY_PAYMENT_TYPE );
		self::assertSame( '_mo_paynow_order_no', Keys::PAYNOW_ORDER_NO );
		self::assertSame( '_mo_invoice_provider', Keys::INVOICE_PROVIDER );
		self::assertSame( '_mo_tappay_rec_trade_id', Keys::TAPPAY_REC_TRADE_ID );
	}

	public function test_no_duplicate_values(): void {
		$reflection = new \ReflectionClass( Keys::class );
		$values     = array_filter( $reflection->getConstants(), 'is_string' );
		self::assertSame(
			count( $values ),
			count( array_unique( $values ) ),
			'Keys 常數有重複值（不同名稱但 mapping 到同 meta key）'
		);
	}
}
