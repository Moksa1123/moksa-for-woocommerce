<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod;

defined( 'ABSPATH' ) || exit;

final class Cvs711 extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_smilepay_shipping_cvs_711';
		$this->method_title       = __( '速買配 — 7-11 取貨', 'mo-ectools' );
		$this->method_description = __( '速買配 7-ELEVEN 超商取貨。', 'mo-ectools' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return '711';
	}

	public function carrier_label(): string {
		return __( '7-11', 'mo-ectools' );
	}

	public function types_server(): string {
		return 'B2C' === get_option( 'moksafowo_smilepay_shipping_cvs_service_type', 'C2C' ) ? '711B2C' : '711C2C';
	}
}
