<?php
declare( strict_types=1 );

namespace Moksafowo\Tests\Unit;

use Moksafowo\Modules\Ecpay\PaymentTypeCatalog as EcpayCatalog;
use Moksafowo\Modules\Newebpay\PaymentTypeCatalog as NewebpayCatalog;
use Moksafowo\Modules\Paynow\PaymentTypeCatalog as PaynowCatalog;
use Moksafowo\Modules\Pchomepay\PaymentTypeCatalog as PchomepayCatalog;
use PHPUnit\Framework\TestCase;

final class PaymentTypeCatalogsTest extends TestCase {

	public function test_ecpay_known_label(): void {
		self::assertSame( '信用卡 — 一次付清', EcpayCatalog::label( 'Credit_CreditCard' ) );
		self::assertSame( '超商代碼繳費', EcpayCatalog::label( 'CVS_CVS' ) );
	}

	public function test_ecpay_prefix_fallback(): void {
		// 未列舉的銀行落到大類別前綴
		self::assertSame( 'ATM 虛擬帳號', EcpayCatalog::label( 'ATM_UNKNOWN_BANK' ) );
		self::assertSame( '網路 ATM', EcpayCatalog::label( 'WebATM_UNKNOWN' ) );
		self::assertSame( '無卡分期', EcpayCatalog::label( 'BNPL_NEW_PARTNER' ) );
	}

	public function test_ecpay_empty_returns_default(): void {
		self::assertSame( '綠界', EcpayCatalog::label( '' ) );
		self::assertSame( 'custom', EcpayCatalog::label( '', 'custom' ) );
	}

	public function test_newebpay_known_label(): void {
		self::assertSame( '信用卡', NewebpayCatalog::label( 'CREDIT' ) );
		self::assertSame( 'LINE Pay', NewebpayCatalog::label( 'LINEPAY' ) );
		self::assertSame( 'AFTEE 無卡分期', NewebpayCatalog::label( 'AFTEE' ) );
	}

	public function test_newebpay_unknown_with_fallback(): void {
		self::assertSame( 'FOO', NewebpayCatalog::label( 'FOO' ) );
		self::assertSame( '備援文字', NewebpayCatalog::label( 'FOO', '備援文字' ) );
	}

	public function test_paynow_known_codes(): void {
		self::assertSame( '信用卡', PaynowCatalog::label( '01' ) );
		self::assertSame( 'ATM 虛擬帳號', PaynowCatalog::label( '03' ) );
		self::assertSame( '銀聯卡', PaynowCatalog::label( '09' ) );
		self::assertSame( '信用卡分期', PaynowCatalog::label( '11' ) );
	}

	public function test_pchomepay_known(): void {
		self::assertSame( '信用卡', PchomepayCatalog::label( 'CARD' ) );
		self::assertSame( '拍錢包', PchomepayCatalog::label( 'PI' ) );
		self::assertSame( '7-11 取貨付款', PchomepayCatalog::label( 'IPL7' ) );
	}

	public function test_pchomepay_logistic(): void {
		self::assertSame( '商品已至寄件門店', PchomepayCatalog::logistic_label( 'seller_dispatched' ) );
		self::assertSame( '商品已至取件門店', PchomepayCatalog::logistic_label( 'pickup_shipped' ) );
	}
}
