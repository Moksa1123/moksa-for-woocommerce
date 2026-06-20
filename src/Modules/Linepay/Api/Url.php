<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay\Api;

use MoksaWeb\Mowc\Modules\Linepay\Constants;

defined( 'ABSPATH' ) || exit;

final class Url {

	public static function request_url( string $type, array $args = [] ): string {
		return self::request_host() . self::request_uri( $type, $args );
	}

	public static function request_host(): string {
		switch ( \MoksaWeb\Mowc\Modules\Linepay\LinePay::$env_status ) {
			case Constants::ENV_SANDBOX:
				return Constants::HOST_SANDBOX;
			case Constants::ENV_REAL:
			default:
				return Constants::HOST_REAL;
		}
	}

	public static function request_uri( string $type, array $args = [] ): string {
		$uri = '';
		switch ( $type ) {
			case Constants::REQUEST_TYPE_REQUEST:
				$uri = Constants::URI_REQUEST;
				break;
			case Constants::REQUEST_TYPE_CONFIRM:
				$uri = Constants::URI_CONFIRM;
				break;
			case Constants::REQUEST_TYPE_DETAILS:
				$uri = Constants::URI_DETAILS;
				break;
			case Constants::REQUEST_TYPE_CHECK:
				$uri = Constants::URI_CHECK;
				break;
			case Constants::REQUEST_TYPE_REFUND:
				$uri = Constants::URI_REFUND;
				break;
		}
		foreach ( $args as $key => $value ) {
			$uri = str_replace( '{' . $key . '}', (string) $value, $uri );
		}
		return $uri;
	}
}
