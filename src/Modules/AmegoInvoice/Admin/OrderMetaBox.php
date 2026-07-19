<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\AmegoInvoice\Admin;

use Moksafowo\Modules\AmegoInvoice\Operations\Allowance;
use Moksafowo\Modules\AmegoInvoice\Operations\Invalid;
use Moksafowo\Modules\AmegoInvoice\Operations\Issue;
use Moksafowo\Modules\Shared\Invoice\AbstractAdminMetaBox;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox extends AbstractAdminMetaBox {

	protected static function provider_key(): string {
		return 'amego';
	}

	protected static function provider_label(): string {
		return __( 'AMEGO', 'moksa-for-woocommerce' );
	}

	protected static function nonce_action(): string {
		return 'moksafowo_amego_invoice_admin';
	}

	protected static function ajax_action_prefix(): string {
		return 'moksafowo_amego_invoice';
	}

	protected static function invoice_number_meta_key(): string {
		return Keys::AMEGO_INVOICE_NUMBER;
	}

	protected static function issued_at_meta_key(): string {
		return Keys::AMEGO_INVOICE_ISSUED_AT;
	}

	protected static function invalid_at_meta_key(): string {
		return Keys::AMEGO_INVOICE_INVALID_AT;
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
		return Keys::AMEGO_INVOICE_ALLOWANCE_NO;
	}

	protected static function allowance_amt_meta_key(): string {
		return Keys::AMEGO_INVOICE_ALLOWANCE_AMT;
	}

	protected static function extra_card_meta( \WC_Order $order ): array {
		return [
			__( '隨機碼', 'moksa-for-woocommerce' ) => (string) $order->get_meta( Keys::AMEGO_INVOICE_RANDOM_NUM ),
			__( '條碼', 'moksa-for-woocommerce' )  => (string) $order->get_meta( Keys::AMEGO_INVOICE_BARCODE ),
		];
	}
}
