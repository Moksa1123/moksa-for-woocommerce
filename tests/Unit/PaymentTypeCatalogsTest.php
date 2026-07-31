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
		self::assertSame( 'Credit card — pay in full', EcpayCatalog::label( 'Credit_CreditCard' ) );
		self::assertSame( 'Convenience store code payment', EcpayCatalog::label( 'CVS_CVS' ) );
	}

	public function test_ecpay_prefix_fallback(): void {
		// 未列舉的銀行落到大類別前綴
		self::assertSame( 'ATM virtual account', EcpayCatalog::label( 'ATM_UNKNOWN_BANK' ) );
		self::assertSame( 'WebATM', EcpayCatalog::label( 'WebATM_UNKNOWN' ) );
		self::assertSame( 'Buy now, pay later', EcpayCatalog::label( 'BNPL_NEW_PARTNER' ) );
	}

	public function test_ecpay_empty_returns_default(): void {
		self::assertSame( 'ECPay', EcpayCatalog::label( '' ) );
		self::assertSame( 'custom', EcpayCatalog::label( '', 'custom' ) );
	}

	public function test_newebpay_known_label(): void {
		self::assertSame( 'Credit card', NewebpayCatalog::label( 'CREDIT' ) );
		self::assertSame( 'LINE Pay', NewebpayCatalog::label( 'LINEPAY' ) );
		self::assertSame( 'AFTEE buy now, pay later', NewebpayCatalog::label( 'AFTEE' ) );
	}

	public function test_newebpay_unknown_with_fallback(): void {
		self::assertSame( 'FOO', NewebpayCatalog::label( 'FOO' ) );
		self::assertSame( '備援文字', NewebpayCatalog::label( 'FOO', '備援文字' ) );
	}

	public function test_paynow_known_codes(): void {
		self::assertSame( 'Credit card', PaynowCatalog::label( '01' ) );
		self::assertSame( 'ATM virtual account', PaynowCatalog::label( '03' ) );
		self::assertSame( 'UnionPay', PaynowCatalog::label( '09' ) );
		self::assertSame( 'Credit card instalments', PaynowCatalog::label( '11' ) );
	}

	public function test_pchomepay_known(): void {
		self::assertSame( 'Credit card', PchomepayCatalog::label( 'CARD' ) );
		self::assertSame( 'Pi Wallet', PchomepayCatalog::label( 'PI' ) );
		self::assertSame( '7-ELEVEN pickup and pay', PchomepayCatalog::label( 'IPL7' ) );
	}

	public function test_pchomepay_logistic(): void {
		self::assertSame( 'Arrived at the drop-off store', PchomepayCatalog::logistic_label( 'seller_dispatched' ) );
		self::assertSame( 'Arrived at the pickup store', PchomepayCatalog::logistic_label( 'pickup_shipped' ) );
	}
}
