<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay\Api;

defined( 'ABSPATH' ) || exit;

final class Url {

	public static function request_url( string $type, array $args = [] ): string {
		return self::request_host() . self::request_uri( $type, $args );
	}

	public static function request_host(): string {
		switch ( \Mo_LinePay::$env_status ) {
			case \Mo_LinePay_Const::ENV_SANDBOX:
				return \Mo_LinePay_Const::HOST_SANDBOX;
			case \Mo_LinePay_Const::ENV_REAL:
			default:
				return \Mo_LinePay_Const::HOST_REAL;
		}
	}

	public static function request_uri( string $type, array $args = [] ): string {
		$uri = '';
		switch ( $type ) {
			case \Mo_LinePay_Const::REQUEST_TYPE_REQUEST: $uri = \Mo_LinePay_Const::URI_REQUEST; break;
			case \Mo_LinePay_Const::REQUEST_TYPE_CONFIRM: $uri = \Mo_LinePay_Const::URI_CONFIRM; break;
			case \Mo_LinePay_Const::REQUEST_TYPE_DETAILS: $uri = \Mo_LinePay_Const::URI_DETAILS; break;
			case \Mo_LinePay_Const::REQUEST_TYPE_CHECK:   $uri = \Mo_LinePay_Const::URI_CHECK;   break;
			case \Mo_LinePay_Const::REQUEST_TYPE_REFUND:  $uri = \Mo_LinePay_Const::URI_REFUND;  break;
		}
		foreach ( $args as $key => $value ) {
			$uri = str_replace( '{' . $key . '}', (string) $value, $uri );
		}
		return $uri;
	}
}
