<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayShipping\Methods;

use MoksaWeb\Mowc\Modules\Shipping\Methods\AbstractHomeShippingMethod;
use MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class Tcat extends AbstractHomeShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'mo_smilepay_shipping_tcat';
		$this->method_title       = __( '速買配 — 黑貓宅配（多溫層）', 'mo-ectools' );
		$this->method_description = __( 'SmilePay 黑貓宅急便。商品溫層混合（常溫 / 冷藏 / 冷凍）時，後台建單自動拆 N 包送 SmilePay API（Pay_zg 78/79/80），各取獨立物流單號。', 'mo-ectools' );
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

	public static function payzg_for_temp( int $temp ): string {
		return match ( $temp ) {
			ProductTemp::REFRIGERATED => '79',
			ProductTemp::FROZEN       => '80',
			default                   => '78',
		};
	}

	public function supported_temperatures(): array {
		return [
			ProductTemp::NORMAL       => __( '常溫', 'mo-ectools' ),
			ProductTemp::REFRIGERATED => __( '冷藏', 'mo-ectools' ),
			ProductTemp::FROZEN       => __( '冷凍', 'mo-ectools' ),
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
