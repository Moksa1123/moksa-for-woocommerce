<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Methods;

defined( 'ABSPATH' ) || exit;

abstract class AbstractCvsShippingMethod extends AbstractShippingMethod {

	public function needs_store_selection(): bool {
		return true;
	}

	abstract public function carrier(): string;

	abstract public function carrier_label(): string;
}
