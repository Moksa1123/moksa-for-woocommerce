<?php
declare( strict_types=1 );

namespace Moksafowo\Tests\Unit;

use Moksafowo\Modules\Shared\Admin\CardRenderers;
use PHPUnit\Framework\TestCase;

/**
 * LINE Pay 退款交易編號在訂單上存成陣列（每退一次加一筆），舊資料可能是字串。
 * 付款卡片原本直接 (string) 轉換：陣列會印成「Array」並丟 PHP 警告。
 */
final class LinepayRefundIdsTest extends TestCase {

	public function test_array_of_ids_joined(): void {
		self::assertSame( '2026111700001, 2026111700002', CardRenderers::linepay_refund_ids_text( array( '2026111700001', 2026111700002 ) ) );
	}

	public function test_legacy_string_kept(): void {
		self::assertSame( '2026111700001', CardRenderers::linepay_refund_ids_text( '2026111700001' ) );
	}

	public function test_empty_values(): void {
		self::assertSame( '', CardRenderers::linepay_refund_ids_text( '' ) );
		self::assertSame( '', CardRenderers::linepay_refund_ids_text( array() ) );
		self::assertSame( '', CardRenderers::linepay_refund_ids_text( null ) );
		self::assertSame( 'A1', CardRenderers::linepay_refund_ids_text( array( '', ' A1 ', array( 'nested' ) ) ) );
	}

	public function test_refund_code_uses_meta_key_constant(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Modules/Linepay/Api/PaymentRequest.php' );
		self::assertStringNotContainsString( "'_moksafowo_linepay_refund_transaction_id'", $src );
		self::assertStringContainsString( 'Keys::LINEPAY_REFUND_TRANSACTION_ID', $src );
	}
}
