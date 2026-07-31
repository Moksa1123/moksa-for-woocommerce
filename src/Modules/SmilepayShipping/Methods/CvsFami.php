<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod;

defined( 'ABSPATH' ) || exit;

final class CvsFami extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_smilepay_shipping_cvs_fami';
		$this->method_title       = __( 'SmilePay — FamilyMart pickup', 'moksa-for-woocommerce' );
		$this->method_description = __( 'SmilePay FamilyMart store pickup.', 'moksa-for-woocommerce' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'fami';
	}

	public function carrier_label(): string {
		return __( 'FamilyMart', 'moksa-for-woocommerce' );
	}

	public function types_server(): string {
		return 'B2C' === get_option( 'moksafowo_smilepay_shipping_cvs_service_type', 'C2C' ) ? 'FAMIB2C' : 'FAMIC2C';
	}
}
