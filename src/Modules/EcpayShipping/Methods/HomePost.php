<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractHomeShippingMethod;

defined( 'ABSPATH' ) || exit;

final class HomePost extends AbstractHomeShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_ecpay_shipping_home_post';
		$this->method_title       = __( 'ECPay — Chunghwa Post', 'moksa-for-woocommerce' );
		$this->method_description = __( 'ECPay Chunghwa Post, which does not support cash on delivery.', 'moksa-for-woocommerce' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'post';
	}

	public function carrier_label(): string {
		return __( 'Chunghwa Post', 'moksa-for-woocommerce' );
	}

	public function logistics_sub_type(): string {
		return 'POST';
	}

	public function supported_temperatures(): array {
		// ECPay spec：中華郵政只支援 Temperature=0001 常溫
		return [ '1' => __( 'Ambient', 'moksa-for-woocommerce' ) ];
	}

	public function supported_package_specs(): array {
		return [];
	}
}
