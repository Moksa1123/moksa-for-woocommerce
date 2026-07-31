<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class Cvs711B2CFreeze extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_ecpay_shipping_cvs_711_b2c_freeze';
		$this->method_title       = __( 'ECPay — 7-ELEVEN B2C frozen pickup', 'moksa-for-woocommerce' );
		$this->method_description = __( 'ECPay 7-ELEVEN frozen store pickup, suited to frozen food stores. You need to apply for bulk shipping with ECPay first.', 'moksa-for-woocommerce' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return '711';
	}

	public function carrier_label(): string {
		return __( '7-ELEVEN frozen', 'moksa-for-woocommerce' );
	}

	public function logistics_sub_type(): string {
		return 'UNIMARTFREEZE';
	}

	public function supported_temperatures(): array {
		return [ ProductTemp::FROZEN => __( 'Frozen', 'moksa-for-woocommerce' ) ];
	}
}
