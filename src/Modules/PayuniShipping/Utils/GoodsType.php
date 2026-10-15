<?php

namespace Moksafowo\Modules\PayuniShipping\Utils;

defined( 'ABSPATH' ) || exit;

class GoodsType {

	const NORMAL       = '1'; // 常溫
	const FROZEN       = '2'; // 冷凍
	const REFRIGERATED = '3'; // 冷藏

	public static function get_name( $goods_type_id ) {
		switch ( $goods_type_id ) {
			case self::NORMAL:
				return __( 'Ambient', 'moksa-for-woocommerce' );
			case self::FROZEN:
				return __( 'Frozen', 'moksa-for-woocommerce' );
			case self::REFRIGERATED:
				return __( 'Chilled', 'moksa-for-woocommerce' );
			default:
				return (string) $goods_type_id; // 舊版存中文名稱時原樣回傳
		}
	}
}
