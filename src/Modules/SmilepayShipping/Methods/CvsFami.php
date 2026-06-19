<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayShipping\Methods;

use MoksaWeb\Mowc\Modules\Shipping\Methods\AbstractCvsShippingMethod;

defined( 'ABSPATH' ) || exit;

final class CvsFami extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_smilepay_shipping_cvs_fami';
		$this->method_title       = __( '速買配 — 全家取貨', 'mo-ectools' );
		$this->method_description = __( '速買配全家超商取貨。', 'mo-ectools' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'fami';
	}

	public function carrier_label(): string {
		return __( '全家', 'mo-ectools' );
	}

	public function types_server(): string {
		return 'B2C' === get_option( 'moksafowo_smilepay_shipping_cvs_service_type', 'C2C' ) ? 'FAMIB2C' : 'FAMIC2C';
	}
}
