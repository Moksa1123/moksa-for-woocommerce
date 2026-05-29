<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EzpayInvoice\Admin;

use MoksaWeb\Mowc\Modules\EzpayInvoice\Operations\Allowance;
use MoksaWeb\Mowc\Modules\EzpayInvoice\Operations\Invalid;
use MoksaWeb\Mowc\Modules\EzpayInvoice\Operations\Issue;
use MoksaWeb\Mowc\Modules\Shared\Invoice\AbstractAdminMetaBox;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox extends AbstractAdminMetaBox {

	protected static function provider_key(): string {
		return 'ezpay';
	}

	protected static function provider_label(): string {
		return __( 'ezPay', 'mo-ectools' );
	}

	protected static function nonce_action(): string {
		return 'mo_ezpay_invoice_admin';
	}

	protected static function ajax_action_prefix(): string {
		return 'mo_ezpay_invoice';
	}

	protected static function invoice_number_meta_key(): string {
		return Keys::EZPAY_INVOICE_NUMBER;
	}

	protected static function issued_at_meta_key(): string {
		return Keys::EZPAY_CREATE_TIME;
	}

	protected static function invalid_at_meta_key(): string {
		return Keys::EZPAY_INVALID_AT;
	}

	protected static function issue_callable(): callable {
		return [ Issue::class, 'run' ];
	}

	protected static function invalid_callable(): callable {
		return [ Invalid::class, 'run' ];
	}

	protected static function supports_allowance(): bool {
		return true;
	}

	protected static function allowance_callable(): ?callable {
		return [ Allowance::class, 'run' ];
	}

	protected static function allowance_no_meta_key(): string {
		return Keys::EZPAY_INVOICE_ALLOWANCE_NO;
	}

	protected static function allowance_amt_meta_key(): string {
		return Keys::EZPAY_INVOICE_ALLOWANCE_AMT;
	}

	protected static function extra_card_meta( \WC_Order $order ): array {
		return [
			__( '隨機碼', 'mo-ectools' ) => (string) $order->get_meta( Keys::EZPAY_RANDOM_NUM ),
		];
	}
}
