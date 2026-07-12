<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Utils;

use Moksafowo\Modules\PayuniShipping\PayuniShipping;

defined( 'ABSPATH' ) || exit;

final class MethodIdPredicates {

	public static function needs_cvs( $method_id ): bool {
		// 原 wpbr substr(0,19) bug：常數是 moksafowo_payuni_shipping_711（22 字），prefix-match 才對
		return 0 === strpos( (string) $method_id, 'moksafowo_payuni_shipping_711' );
	}

	public static function is_payuni_shipping( $shipping_method_id ): bool {
		return str_starts_with( (string) $shipping_method_id, 'moksafowo_payuni_shipping' )
			|| str_starts_with( (string) $shipping_method_id, 'moksafowo_payuni_shipping' );
	}

	public static function is_payuni_payment( $payment_method ): bool {
		return 0 === strpos( (string) $payment_method, 'moksafowo-payuni-pro' )
			|| 0 === strpos( (string) $payment_method, 'moksafowo-payuni-upp' );
	}


	public static function is_moksafowo_payuni_shipping_cvs( $shipping_method_id ) {
		foreach ( PayuniShipping::$cvs_methods as $method => $method_class ) {
			if ( 0 === strpos( (string) $shipping_method_id, $method ) ) {
				return $method;
			}
		}
		return false;
	}

	public static function is_moksafowo_payuni_shipping_hd( $shipping_method_id ): bool {
		foreach ( PayuniShipping::$hd_methods as $method => $method_class ) {
			if ( 0 === strpos( (string) $shipping_method_id, $method ) ) {
				return true;
			}
		}
		return false;
	}
}
