<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay\Api;

defined( 'ABSPATH' ) || exit;

final class Url {

	public static function request_url( string $type, array $args = [] ): string {
		return self::request_host() . self::request_uri( $type, $args );
	}

	public static function request_host(): string {
		switch ( \MoksaWeb\Mowc\Modules\Linepay\LinePay::$env_status ) {
			case \Moksafowo_LinePay_Const::ENV_SANDBOX:
				return \Moksafowo_LinePay_Const::HOST_SANDBOX;
			case \Moksafowo_LinePay_Const::ENV_REAL:
			default:
				return \Moksafowo_LinePay_Const::HOST_REAL;
		}
	}

	public static function request_uri( string $type, array $args = [] ): string {
		$uri = '';
		switch ( $type ) {
			case \Moksafowo_LinePay_Const::REQUEST_TYPE_REQUEST: $uri = \Moksafowo_LinePay_Const::URI_REQUEST; break;
			case \Moksafowo_LinePay_Const::REQUEST_TYPE_CONFIRM: $uri = \Moksafowo_LinePay_Const::URI_CONFIRM; break;
			case \Moksafowo_LinePay_Const::REQUEST_TYPE_DETAILS: $uri = \Moksafowo_LinePay_Const::URI_DETAILS; break;
			case \Moksafowo_LinePay_Const::REQUEST_TYPE_CHECK:   $uri = \Moksafowo_LinePay_Const::URI_CHECK;   break;
			case \Moksafowo_LinePay_Const::REQUEST_TYPE_REFUND:  $uri = \Moksafowo_LinePay_Const::URI_REFUND;  break;
		}
		foreach ( $args as $key => $value ) {
			$uri = str_replace( '{' . $key . '}', (string) $value, $uri );
		}
		return $uri;
	}
}
