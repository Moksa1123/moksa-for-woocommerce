<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayInvoice\Admin;

use MoksaWeb\Mowc\Modules\Shared\Invoice\AbstractAdminMetaBox;
use MoksaWeb\Mowc\Modules\SmilepayInvoice\Operations\Invalid;
use MoksaWeb\Mowc\Modules\SmilepayInvoice\Operations\Issue;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox extends AbstractAdminMetaBox {

	protected static function provider_key(): string {
		return 'smilepay';
	}

	protected static function provider_label(): string {
		return __( 'SmilePay', 'mo-ectools' );
	}

	protected static function nonce_action(): string {
		return 'moksafowo_smilepay_invoice_admin';
	}

	protected static function ajax_action_prefix(): string {
		return 'moksafowo_smilepay_invoice';
	}

	protected static function invoice_number_meta_key(): string {
		return Keys::SMILEPAY_INVOICE_NUMBER;
	}

	protected static function issued_at_meta_key(): string {
		return Keys::SMILEPAY_INVOICE_DATE;
	}

	protected static function invalid_at_meta_key(): string {
		return Keys::SMILEPAY_INVOICE_INVALID_AT;
	}

	protected static function issue_callable(): callable {
		return [ Issue::class, 'run' ];
	}

	protected static function invalid_callable(): callable {
		return [ Invalid::class, 'run' ];
	}

	protected static function extra_card_meta( \WC_Order $order ): array {
		return [
			__( '隨機碼', 'mo-ectools' ) => (string) $order->get_meta( Keys::SMILEPAY_INVOICE_RANDOM ),
		];
	}
}
