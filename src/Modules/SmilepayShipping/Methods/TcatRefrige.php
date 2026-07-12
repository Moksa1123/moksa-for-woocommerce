<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractHomeShippingMethod;

defined( 'ABSPATH' ) || exit;

final class TcatRefrige extends AbstractHomeShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_smilepay_shipping_tcat_refrige';
		$this->method_title       = __( '速買配 — 黑貓冷藏', 'mo-ectools' );
		$this->method_description = __( 'SmilePay 黑貓宅急便（冷藏）。', 'mo-ectools' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'tcat';
	}

	public function carrier_label(): string {
		return __( '黑貓冷藏', 'mo-ectools' );
	}

	public function logistics_sub_type(): string {
		return 'TCAT_REFRIGE';
	}

	public function smilepay_payzg(): string {
		return '79';
	}

	public function supported_temperatures(): array {
		return [ '2' => __( '冷藏', 'mo-ectools' ) ];
	}

	public function supported_package_specs(): array {
		return [
			'1' => __( '60cm', 'mo-ectools' ),
			'2' => __( '90cm', 'mo-ectools' ),
			'3' => __( '120cm', 'mo-ectools' ),
		];
	}
}
