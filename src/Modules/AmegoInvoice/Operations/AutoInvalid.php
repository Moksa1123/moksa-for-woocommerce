<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\AmegoInvoice\Operations;

use MoksaWeb\Mowc\Modules\Shared\Invoice\AbstractAutoInvalid;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class AutoInvalid extends AbstractAutoInvalid {

	public const HOOK = 'mo_amego_invoice_auto_invalid';

	protected static function hook_name(): string {
		return self::HOOK;
	}

	protected static function provider_label(): string {
		return __( 'Amego', 'mo-ectools' );
	}

	protected static function invoice_number_meta_key(): string {
		return Keys::AMEGO_INVOICE_NUMBER;
	}

	protected static function scheduled_meta_key(): string {
		return Keys::AMEGO_INVOICE_SCHEDULED_AT;
	}

	protected static function deferred_issue_hook_name(): string {
		return 'mo_amego_invoice_deferred_issue';
	}

	protected static function is_real_invoice_number( string $invoice_no ): bool {
		return '' !== $invoice_no && ! in_array( $invoice_no, [ 'zero', 'negative' ], true );
	}

	protected static function invoke_invalid( \WC_Order $order, string $reason ): void {
		Invalid::run( $order, $reason );
	}
}
