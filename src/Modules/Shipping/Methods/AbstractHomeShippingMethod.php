<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Methods;

defined( 'ABSPATH' ) || exit;

abstract class AbstractHomeShippingMethod extends AbstractShippingMethod {

	public function needs_store_selection(): bool {
		return false;
	}

	abstract public function carrier(): string;
	abstract public function carrier_label(): string;

	abstract public function supported_package_specs(): array;
}
