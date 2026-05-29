<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Methods;

use MoksaWeb\Mowc\Modules\Shipping\Methods\AbstractHomeShippingMethod;

defined( 'ABSPATH' ) || exit;

final class HomePost extends AbstractHomeShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'mo_ecpay_shipping_home_post';
		$this->method_title       = __( '綠界 — 中華郵政', 'mo-ectools' );
		$this->method_description = __( '綠界中華郵政（不接 COD）。', 'mo-ectools' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'post';
	}

	public function carrier_label(): string {
		return __( '中華郵政', 'mo-ectools' );
	}

	public function logistics_sub_type(): string {
		return 'POST';
	}

	public function supported_temperatures(): array {
		// ECPay spec：中華郵政只支援 Temperature=0001 常溫
		return [ '1' => __( '常溫', 'mo-ectools' ) ];
	}

	public function supported_package_specs(): array {
		return [];
	}
}
