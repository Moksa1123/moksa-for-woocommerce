<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayShipping\Methods;

use MoksaWeb\Mowc\Modules\Shipping\Methods\AbstractHomeShippingMethod;

defined( 'ABSPATH' ) || exit;

final class TcatFreeze extends AbstractHomeShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_smilepay_shipping_tcat_freeze';
		$this->method_title       = __( '速買配 — 黑貓冷凍', 'mo-ectools' );
		$this->method_description = __( 'SmilePay 黑貓宅急便（冷凍）。', 'mo-ectools' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'tcat';
	}

	public function carrier_label(): string {
		return __( '黑貓冷凍', 'mo-ectools' );
	}

	public function logistics_sub_type(): string {
		return 'TCAT_FREEZE';
	}

	public function smilepay_payzg(): string {
		return '80';
	}

	public function supported_temperatures(): array {
		return [ '3' => __( '冷凍', 'mo-ectools' ) ];
	}

	public function supported_package_specs(): array {
		return [
			'1' => __( '60cm', 'mo-ectools' ),
			'2' => __( '90cm', 'mo-ectools' ),
			'3' => __( '120cm', 'mo-ectools' ),
		];
	}
}
