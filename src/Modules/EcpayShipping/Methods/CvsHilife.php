<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Methods;

use MoksaWeb\Mowc\Modules\Shipping\Methods\AbstractCvsShippingMethod;

defined( 'ABSPATH' ) || exit;

final class CvsHilife extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'mo_ecpay_shipping_cvs_hilife';
		$this->method_title       = __( '綠界 — 萊爾富取貨', 'mo-ectools' );
		$this->method_description = __( '綠界萊爾富取貨。', 'mo-ectools' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'hilife';
	}

	public function carrier_label(): string {
		return __( '萊爾富', 'mo-ectools' );
	}

	public function logistics_sub_type(): string {
		return 'B2C' === get_option( 'mo_ecpay_shipping_cvs_type', 'C2C' ) ? 'HILIFE' : 'HILIFEC2C';
	}
}
