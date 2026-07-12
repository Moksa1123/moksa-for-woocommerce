<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractHomeShippingMethod;

defined( 'ABSPATH' ) || exit;

final class TcatNormal extends AbstractHomeShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_smilepay_shipping_tcat_normal';
		$this->method_title       = __( '速買配 — 黑貓常溫', 'mo-ectools' );
		$this->method_description = __( 'SmilePay 黑貓宅急便（常溫）。', 'mo-ectools' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'tcat';
	}

	public function carrier_label(): string {
		return __( '黑貓常溫', 'mo-ectools' );
	}

	public function logistics_sub_type(): string {
		return 'TCAT_NORMAL';
	}

	public function smilepay_payzg(): string {
		return '78';
	}

	public function supported_temperatures(): array {
		return [ '1' => __( '常溫', 'mo-ectools' ) ];
	}

	public function supported_package_specs(): array {
		return [
			'1' => __( '60cm', 'mo-ectools' ),
			'2' => __( '90cm', 'mo-ectools' ),
			'3' => __( '120cm', 'mo-ectools' ),
		];
	}
}
