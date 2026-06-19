<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Utils;

defined( 'ABSPATH' ) || exit;

class LgsType {

	const HOME = 'HOME'; // 宅配
	const B2C  = 'B2C'; // 大宗寄倉
	const C2C  = 'C2C'; // 店到店

	public static function get_name( $lgs_type ) {
		switch ( $lgs_type ) {
			case self::HOME:
				return '宅配';
			case self::B2C:
				return '大宗寄倉';
			case self::C2C:
				return '店到店';
			case '黑貓': // 舊版誤存通路名
			case '宅配':
				return '宅配';
			default:
				return (string) $lgs_type;
		}
	}

	public static function get_lgs_type_by_shipping_method( $shipping_method ) {
		switch ( $shipping_method ) {
			case 'moksafowo_payuni_shipping_711_c2c':
				return self::C2C;
			case 'moksafowo_payuni_shipping_711_b2c':
				return self::B2C;
			case 'moksafowo_payuni_shipping_tcat':
				return self::HOME;
			case 'moksafowo_payuni_shipping_711_b2c_normal':
				return self::B2C;
			case 'moksafowo_payuni_shipping_711_b2c_frozen':
				return self::B2C;
			case 'moksafowo_payuni_shipping_711_c2c_normal':
				return self::C2C;
			case 'moksafowo_payuni_shipping_711_c2c_frozen':
				return self::C2C;
			case 'moksafowo_payuni_shipping_tcat_normal':
				return self::HOME;
			case 'moksafowo_payuni_shipping_tcat_frozen':
				return self::HOME;
			case 'moksafowo_payuni_shipping_tcat_refrigerated':
				return self::HOME;
			default:
				return '';
		}
	}
}
