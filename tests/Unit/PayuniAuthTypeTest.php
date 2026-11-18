<?php
declare( strict_types=1 );

namespace Moksafowo\Tests\Unit;

use Moksafowo\Modules\Payuni\Utils\AuthType;
use PHPUnit\Framework\TestCase;

/**
 * 統一金流授權類型：官方 UPP 與交易查詢的 AuthType=8 是 Click to Pay（11-18 新增）。
 * 原本沒對應，訂單頁與顧客的付款明細會顯示「未知的授權類型」。
 */
final class PayuniAuthTypeTest extends TestCase {

	public function test_click_to_pay_is_named(): void {
		self::assertSame( 'Click to Pay', AuthType::get_type( '8' ) );
	}

	public function test_existing_types_unchanged(): void {
		self::assertSame( 'Apple Pay', AuthType::get_type( '4' ) );
		self::assertSame( 'Samsung Pay', AuthType::get_type( '6' ) );
	}

	public function test_click_to_pay_gateway_sends_official_flag(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Modules/Payuni/Gateways/ClickToPay.php' );
		self::assertStringContainsString( "\$args['ClickToPay'] = '1';", $src );
		$unified = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Modules/Payuni/Gateways/Unified.php' );
		self::assertStringContainsString( "'moksafowo_payuni_clicktopay' => 'ClickToPay'", $unified );
	}
}
