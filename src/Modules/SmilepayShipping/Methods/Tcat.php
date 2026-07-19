<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractHomeShippingMethod;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class Tcat extends AbstractHomeShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_smilepay_shipping_tcat';
		$this->method_title       = __( '速買配 — 黑貓宅配（多溫層）', 'moksa-for-woocommerce' );
		$this->method_description = __( '速買配黑貓宅急便，支援常溫 / 冷藏 / 冷凍多溫層配送。', 'moksa-for-woocommerce' );
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

	public static function payzg_for_temp( int $temp ): string {
		return match ( $temp ) {
			ProductTemp::REFRIGERATED => '79',
			ProductTemp::FROZEN       => '80',
			default                   => '78',
		};
	}

	public function supported_temperatures(): array {
		return [
			ProductTemp::NORMAL       => __( '常溫', 'moksa-for-woocommerce' ),
			ProductTemp::REFRIGERATED => __( '冷藏', 'moksa-for-woocommerce' ),
			ProductTemp::FROZEN       => __( '冷凍', 'moksa-for-woocommerce' ),
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
