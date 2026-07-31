<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Emails;

defined( 'ABSPATH' ) || exit;

final class EmailCvsArrived extends AbstractShippingEmail {

	public function __construct() {
		$this->title       = __( 'Shipping: parcel arrived at store', 'moksa-for-woocommerce' );
		$this->description = __( 'Sent to the customer when the parcel reaches the convenience store, including the store name, store number and collection deadline.', 'moksa-for-woocommerce' );
		parent::__construct();
	}

	protected function get_status_slug(): string {
		return 'moksa-cvs-arrived';
	}

	public function get_default_subject(): string {
		return __( 'Your parcel has arrived at {store_name} (order #{order_number})', 'moksa-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'Parcel ready for pickup', 'moksa-for-woocommerce' );
	}
}
