<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EzpayInvoice\Frontend;

use Moksafowo\Modules\Shared\Invoice\InvoiceCheckoutFields;
use Moksafowo\Modules\Shared\Invoice\InvoiceFieldConfig;

defined( 'ABSPATH' ) || exit;

final class CheckoutFields {

	public static function init(): void {
		InvoiceCheckoutFields::boot(
			new InvoiceFieldConfig(
				provider_slug: 'ezpay',
				option_prefix: 'moksafowo_ezpay_invoice',
				member_label: __( 'ezPay 會員載具', 'mo-ectools' ),
			)
		);
	}
}
