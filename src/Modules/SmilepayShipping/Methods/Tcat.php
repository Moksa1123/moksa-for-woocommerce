<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractHomeShippingMethod;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class Tcat extends AbstractHomeShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_smilepay_shipping_tcat';
		$this->method_title       = __( 'SmilePay — T-Cat home delivery (mixed temperature zones)', 'moksa-for-woocommerce' );
		$this->method_description = __( 'SmilePay T-Cat delivery, covering ambient, chilled and frozen goods in one order.', 'moksa-for-woocommerce' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'tcat';
	}

	public function carrier_label(): string {
		return __( 'T-Cat home delivery', 'moksa-for-woocommerce' );
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
			ProductTemp::NORMAL       => __( 'Ambient', 'moksa-for-woocommerce' ),
			ProductTemp::REFRIGERATED => __( 'Chilled', 'moksa-for-woocommerce' ),
			ProductTemp::FROZEN       => __( 'Frozen', 'moksa-for-woocommerce' ),
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
