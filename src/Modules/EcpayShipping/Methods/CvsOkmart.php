<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod;

defined( 'ABSPATH' ) || exit;

final class CvsOkmart extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_ecpay_shipping_cvs_okmart';
		$this->method_title       = __( '綠界 — OK 取貨', 'mo-ectools' );
		$this->method_description = __( '綠界 OK 取貨。', 'mo-ectools' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'ok';
	}

	public function carrier_label(): string {
		return __( 'OK', 'mo-ectools' );
	}

	public function logistics_sub_type(): string {
		return 'B2C' === get_option( 'moksafowo_ecpay_shipping_cvs_type', 'C2C' ) ? 'OKMART' : 'OKMARTC2C';
	}
}
