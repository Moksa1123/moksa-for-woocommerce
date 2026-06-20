<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\AmegoInvoice\Frontend;

use MoksaWeb\Mowc\Modules\Shared\Invoice\InvoiceCheckoutFields;
use MoksaWeb\Mowc\Modules\Shared\Invoice\InvoiceFieldConfig;

defined( 'ABSPATH' ) || exit;

final class CheckoutFields {

	public static function init(): void {
		InvoiceCheckoutFields::boot(
			new InvoiceFieldConfig(
				provider_slug: 'amego',
				option_prefix: 'moksafowo_amego_invoice',
				member_label: __( 'AMEGO 會員載具', 'mo-ectools' ),
			)
		);
	}
}
