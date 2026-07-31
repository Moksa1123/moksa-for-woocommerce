<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Emails;

defined( 'ABSPATH' ) || exit;

final class EmailShipped extends AbstractShippingEmail {

	public function __construct() {
		$this->title       = __( 'Shipping: order shipped', 'moksa-for-woocommerce' );
		$this->description = __( 'Sent to the customer when the order status changes to Shipped.', 'moksa-for-woocommerce' );
		parent::__construct();
	}

	protected function get_status_slug(): string {
		return 'moksa-shipped';
	}

	public function get_default_subject(): string {
		return __( 'Your {site_title} order #{order_number} has shipped', 'moksa-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'Order shipped', 'moksa-for-woocommerce' );
	}
}
