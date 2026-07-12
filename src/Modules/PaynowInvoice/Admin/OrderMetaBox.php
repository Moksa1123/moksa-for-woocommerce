<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PaynowInvoice\Admin;

use Moksafowo\Modules\PaynowInvoice\Operations\Invalid;
use Moksafowo\Modules\PaynowInvoice\Operations\Issue;
use Moksafowo\Modules\Shared\Invoice\AbstractAdminMetaBox;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox extends AbstractAdminMetaBox {

	protected static function provider_key(): string {
		return 'paynow';
	}

	protected static function provider_label(): string {
		return __( 'PayNow', 'mo-ectools' );
	}

	protected static function nonce_action(): string {
		return 'moksafowo_paynow_invoice_admin';
	}

	protected static function ajax_action_prefix(): string {
		return 'moksafowo_paynow_invoice';
	}

	protected static function invoice_number_meta_key(): string {
		return Keys::PAYNOW_INVOICE_NUMBER;
	}

	protected static function issued_at_meta_key(): string {
		return Keys::PAYNOW_INVOICE_ISSUED_AT;
	}

	protected static function invalid_at_meta_key(): string {
		return Keys::PAYNOW_INVOICE_INVALID_AT;
	}

	protected static function issue_callable(): callable {
		return [ Issue::class, 'run' ];
	}

	protected static function invalid_callable(): callable {
		return [ Invalid::class, 'run' ];
	}

	// PayNow EInvoice v1.5 spec 沒有公開折讓 endpoint，supports_allowance() 保留 false
}
