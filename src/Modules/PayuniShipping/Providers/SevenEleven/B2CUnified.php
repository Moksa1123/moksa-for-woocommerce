<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Providers\SevenEleven;

use Moksafowo\Modules\PayuniShipping\Utils\GoodsType;
use Moksafowo\Modules\PayuniShipping\Utils\LgsType;
use Moksafowo\Modules\PayuniShipping\Utils\ShipType;
use Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class B2CUnified extends AbstractCvsShippingMethod {

	public const ID = 'moksafowo_payuni_shipping_711_b2c';

	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::ID;
		$this->method_title       = __( 'PAYUNi — 7-ELEVEN bulk store pickup (mixed temperature zones)', 'moksa-for-woocommerce' );
		$this->method_description = __( 'PAYUNi 7-ELEVEN bulk store pickup, for ambient and frozen goods. 7-ELEVEN does not offer chilled delivery, so chilled products travel at ambient temperature.', 'moksa-for-woocommerce' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return '711';
	}

	public function carrier_label(): string {
		return __( '7-11 B2C', 'moksa-for-woocommerce' );
	}

	public function logistics_sub_type(): string {
		return 'SEVEN_B2C';
	}

	public function moksafowo_payuni_ship_type(): string {
		return ShipType::SEVEN;
	}

	public function moksafowo_payuni_lgs_type(): string {
		return LgsType::B2C;
	}

	public function moksafowo_payuni_api_endpoint(): string {
		return 'logistics';
	}

	public static function moksafowo_payuni_goods_type_for_temp( int $temp ): string {
		return match ( $temp ) {
			ProductTemp::FROZEN => GoodsType::FROZEN,
			default             => GoodsType::NORMAL,
		};
	}

	public function supported_temperatures(): array {
		return [
			ProductTemp::NORMAL => __( 'Ambient', 'moksa-for-woocommerce' ),
			ProductTemp::FROZEN => __( 'Frozen', 'moksa-for-woocommerce' ),
		];
	}
}
