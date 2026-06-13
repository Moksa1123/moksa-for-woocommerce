<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Providers\SevenEleven;

use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\GoodsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\LgsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShipType;
use MoksaWeb\Mowc\Modules\Shipping\Methods\AbstractCvsShippingMethod;
use MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class C2CUnified extends AbstractCvsShippingMethod {

	public const ID = 'moksafowo_payuni_shipping_711_c2c';

	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::ID;
		$this->method_title       = __( 'PAYUNi — 7-11 C2C 取貨（多溫層）', 'mo-ectools' );
		$this->method_description = __( 'PAYUNi 7-11 店到店取貨。商品溫層混合（常溫 / 冷凍）時，後台建單自動拆 N 包送 PAYUNi /logistics/trade API。注意 7-11 不支援冷藏，冷藏商品會以常溫運送。', 'mo-ectools' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return '711';
	}

	public function carrier_label(): string {
		return __( '7-11 C2C', 'mo-ectools' );
	}

	public function logistics_sub_type(): string {
		return 'SEVEN_C2C';
	}

	public function moksafowo_payuni_ship_type(): string {
		return ShipType::SEVEN;
	}

	public function moksafowo_payuni_lgs_type(): string {
		return LgsType::C2C;
	}

	public function moksafowo_payuni_api_endpoint(): string {
		return 'logistics';
	}

	public static function moksafowo_payuni_goods_type_for_temp( int $temp ): string {
		return match ( $temp ) {
			ProductTemp::FROZEN => GoodsType::FROZEN, // '2'
			default             => GoodsType::NORMAL,  // '1' (含冷藏 fallback)
		};
	}

	public function supported_temperatures(): array {
		return [
			ProductTemp::NORMAL => __( '常溫', 'mo-ectools' ),
			ProductTemp::FROZEN => __( '冷凍', 'mo-ectools' ),
		];
	}
}
