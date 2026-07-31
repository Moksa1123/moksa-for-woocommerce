<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class Cvs711 extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_ecpay_shipping_cvs_711';
		$this->method_title       = __( 'ECPay — 7-ELEVEN pickup', 'moksa-for-woocommerce' );
		$this->method_description = __( 'ECPay 7-ELEVEN store pickup.', 'moksa-for-woocommerce' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return '711';
	}

	public function carrier_label(): string {
		return __( '7-11', 'moksa-for-woocommerce' );
	}

	public function logistics_sub_type(): string {
		return 'B2C' === get_option( 'moksafowo_ecpay_shipping_cvs_type', 'C2C' ) ? 'UNIMART' : 'UNIMARTC2C';
	}

	public function supported_temperatures(): array {
		if ( 'B2C' === get_option( 'moksafowo_ecpay_shipping_cvs_type', 'C2C' ) ) {
			return [
				ProductTemp::NORMAL => __( 'Ambient', 'moksa-for-woocommerce' ),
				ProductTemp::FROZEN => __( 'Frozen', 'moksa-for-woocommerce' ),
			];
		}
		return [ ProductTemp::NORMAL => __( 'Ambient', 'moksa-for-woocommerce' ) ];
	}
}
