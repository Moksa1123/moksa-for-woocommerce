<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Emails;

defined( 'ABSPATH' ) || exit;

final class EmailStoreClosed extends AbstractShippingEmail {

	public function __construct() {
		$this->title       = __( 'Shipping: store closed', 'moksa-for-woocommerce' );
		$this->description = __( 'An urgent notice asking the customer to choose another store when the pickup or return store is temporarily closed.', 'moksa-for-woocommerce' );
		parent::__construct();
	}

	protected function get_status_slug(): string {
		return 'moksa-store-closed';
	}

	public function get_default_subject(): string {
		return __( '[Important] Please choose another pickup store (order #{order_number})', 'moksa-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'Store closed — please choose another pickup store', 'moksa-for-woocommerce' );
	}
}
