<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayInvoice\Frontend;

use Moksafowo\Modules\Shared\Invoice\InvoiceCheckoutFields;
use Moksafowo\Modules\Shared\Invoice\InvoiceFieldConfig;

defined( 'ABSPATH' ) || exit;

final class CheckoutFields {

	public static function init(): void {
		InvoiceCheckoutFields::boot(
			new InvoiceFieldConfig(
				provider_slug: 'smilepay',
				option_prefix: 'moksafowo_smilepay_invoice',
				member_label: __( 'SmilePay 會員載具', 'moksa-for-woocommerce' ),
			)
		);
	}
}
