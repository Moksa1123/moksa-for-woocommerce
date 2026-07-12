<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PaynowInvoice\Frontend;

use Moksafowo\Modules\Shared\Invoice\InvoiceCheckoutFields;
use Moksafowo\Modules\Shared\Invoice\InvoiceFieldConfig;

defined( 'ABSPATH' ) || exit;

final class CheckoutFields {

	public static function init(): void {
		// PayNow 無消費者平台，沒有會員載具 — InvoiceChannels 能力表已過濾掉 member，不必設 label。
		InvoiceCheckoutFields::boot(
			new InvoiceFieldConfig(
				provider_slug: 'paynow',
				option_prefix: 'moksafowo_paynow_invoice',
			)
		);
	}
}
