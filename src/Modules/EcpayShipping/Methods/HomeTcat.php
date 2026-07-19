<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractHomeShippingMethod;

defined( 'ABSPATH' ) || exit;

final class HomeTcat extends AbstractHomeShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_ecpay_shipping_home_tcat';
		$this->method_title       = __( '綠界 — 黑貓宅配', 'moksa-for-woocommerce' );
		$this->method_description = __( '綠界黑貓宅急便。', 'moksa-for-woocommerce' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'tcat';
	}

	public function carrier_label(): string {
		return __( '黑貓宅配', 'moksa-for-woocommerce' );
	}

	public function logistics_sub_type(): string {
		return 'TCAT';
	}

	public function supported_temperatures(): array {
		return [
			'1' => __( '常溫', 'moksa-for-woocommerce' ),
			'2' => __( '冷藏', 'moksa-for-woocommerce' ),
			'3' => __( '冷凍', 'moksa-for-woocommerce' ),
		];
	}

	public function supported_package_specs(): array {
		return [
			'1' => __( '60cm', 'moksa-for-woocommerce' ),
			'2' => __( '90cm', 'moksa-for-woocommerce' ),
			'3' => __( '120cm', 'moksa-for-woocommerce' ),
		];
	}
}
