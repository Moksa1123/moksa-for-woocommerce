<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Utils;

defined( 'ABSPATH' ) || exit;

class GoodsType {
    
    const NORMAL          = '1'; // 常溫
	const FROZEN          = '2'; // 冷凍
    const REFRIGERATED    = '3'; // 冷藏

	public static function get_name( $goods_type_id ) {
		switch ( $goods_type_id ) {
			case self::NORMAL:
				return '常溫';
				break;
			case self::FROZEN:
				return '冷凍';
				break;
            case self::REFRIGERATED:
                return '冷藏';
                break;
			default:
				// 容忍空值 / 舊版資料（直接存中文溫層名）：原樣顯示，不露 debug 字串。
				return (string) $goods_type_id;
		}
	}
	
}
