<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Utils;

defined( 'ABSPATH' ) || exit;

class ServiceType {

	const COD     = 1;
	const NOT_COD = 3;

	public static function get_name( $service_type ) {
		$name = '';
		switch ( $service_type ) {
			case self::COD:
				$name = _x( 'COD', 'service type', 'mo-ectools' );
				break;
			case self::NOT_COD:
				$name = _x( 'Not COD', 'service type', 'mo-ectools' );
				break;
		}
		return $name;
	}
}
