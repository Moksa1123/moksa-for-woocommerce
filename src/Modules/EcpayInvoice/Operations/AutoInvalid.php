<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayInvoice\Operations;

use Moksafowo\Modules\Shared\Invoice\AbstractAutoInvalid;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class AutoInvalid extends AbstractAutoInvalid {

	public const HOOK = 'moksafowo_ecpay_invoice_auto_invalid';

	protected static function hook_name(): string {
		return self::HOOK;
	}

	protected static function provider_label(): string {
		return __( '綠界', 'moksa-for-woocommerce' );
	}

	protected static function invoice_number_meta_key(): string {
		return Keys::ECPAY_INVOICE_NUMBER;
	}

	protected static function scheduled_meta_key(): string {
		return Keys::ECPAY_INVOICE_SCHEDULED_AT;
	}

	protected static function deferred_issue_hook_name(): string {
		return 'moksafowo_ecpay_invoice_deferred_issue';
	}

	protected static function invoke_invalid( \WC_Order $order, string $reason ): void {
		Invalid::run( $order, $reason );
	}
}
