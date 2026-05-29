<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Methods;

use MoksaWeb\Mowc\Modules\Shipping\Methods\AbstractHomeShippingMethod;

defined( 'ABSPATH' ) || exit;

final class HomeTcat extends AbstractHomeShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'mo_ecpay_shipping_home_tcat';
		$this->method_title       = __( '綠界 — 黑貓宅配', 'mo-ectools' );
		$this->method_description = __( '綠界黑貓宅急便。', 'mo-ectools' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'tcat';
	}

	public function carrier_label(): string {
		return __( '黑貓宅配', 'mo-ectools' );
	}

	public function logistics_sub_type(): string {
		return 'TCAT';
	}

	public function supported_temperatures(): array {
		return [
			'1' => __( '常溫', 'mo-ectools' ),
			'2' => __( '冷藏', 'mo-ectools' ),
			'3' => __( '冷凍', 'mo-ectools' ),
		];
	}

	public function supported_package_specs(): array {
		return [
			'1' => __( '60cm', 'mo-ectools' ),
			'2' => __( '90cm', 'mo-ectools' ),
			'3' => __( '120cm', 'mo-ectools' ),
		];
	}
}
