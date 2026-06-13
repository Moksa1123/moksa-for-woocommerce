<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Utils;

defined( 'ABSPATH' ) || exit;

class ShipType {
    
    const SEVEN           = '1'; // 7-11
	const TCAT            = '2'; // 黑貓

    	public static function is_cvs( $service_id ) {

		if ( self::TCAT !== $service_id ) {
			return true;
		} else {
			return false;
		}

	}

	public static function get_name( $ship_type_id ) {
		switch ( $ship_type_id ) {
			case self::SEVEN:
				return '7-Eleven';
				break;
			case self::TCAT:
				return '黑貓宅配';
				break;
			default:
				return '';
		}
	}

	public static function get_ship_type( $method_id ) {

		switch ( $method_id ) {
			case 'moksafowo_payuni_shipping_711_b2c_normal':
				return self::SEVEN;
				break;
			case 'moksafowo_payuni_shipping_711_b2c_frozen':
				return self::SEVEN;
				break;
			case 'moksafowo_payuni_shipping_711_c2c_normal':
				return self::SEVEN;
				break;
			case 'moksafowo_payuni_shipping_711_c2c_frozen':
				return self::SEVEN;
				break;
			case 'moksafowo_payuni_shipping_tcat_normal':
				return self::TCAT;
				break;
			case 'moksafowo_payuni_shipping_tcat_frozen':
				return self::TCAT;
				break;
			case 'moksafowo_payuni_shipping_tcat_refrigerated':
				return self::TCAT;
				break;
			default:
				return '';
		}
	}
	
}
