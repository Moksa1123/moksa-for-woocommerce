<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Providers\TCat;

use Moksafowo\Modules\PayuniShipping\Utils\GoodsType;
use Moksafowo\Modules\PayuniShipping\Utils\LgsType;
use Moksafowo\Modules\PayuniShipping\Utils\ShipType;
use Moksafowo\Modules\Shipping\Methods\AbstractHomeShippingMethod;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class HDUnified extends AbstractHomeShippingMethod {

	public const ID = 'moksafowo_payuni_shipping_tcat';

	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::ID;
		$this->method_title       = __( 'PAYUNi — 黑貓宅配（多溫層）', 'moksa-for-woocommerce' );
		$this->method_description = __( 'PAYUNi 黑貓宅急便，支援常溫 / 冷藏 / 冷凍多溫層配送。', 'moksa-for-woocommerce' );
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

	public function moksafowo_payuni_ship_type(): string {
		return ShipType::TCAT;
	}

	public function moksafowo_payuni_lgs_type(): string {
		return LgsType::HOME;
	}

	public function moksafowo_payuni_api_endpoint(): string {
		return 'home_delivery';
	}

	public static function moksafowo_payuni_goods_type_for_temp( int $temp ): string {
		return match ( $temp ) {
			ProductTemp::REFRIGERATED => GoodsType::REFRIGERATED, // '3'
			ProductTemp::FROZEN       => GoodsType::FROZEN,        // '2'
			default                   => GoodsType::NORMAL,        // '1'
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
