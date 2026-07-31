<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\NewebpayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod;

defined( 'ABSPATH' ) || exit;

final class Cvs extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_newebpay_shipping_cvs';
		$this->method_title       = __( 'NewebPay — convenience store pickup', 'moksa-for-woocommerce' );
		$this->method_description = __( 'NewebPay convenience store pickup. It pairs well with the NewebPay convenience store code, which lets the customer pick the store in the same flow.', 'moksa-for-woocommerce' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'newebpay_cvs';
	}

	public function carrier_label(): string {
		return __( 'NewebPay convenience store', 'moksa-for-woocommerce' );
	}
}
