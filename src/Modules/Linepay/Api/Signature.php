<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay\Api;

defined( 'ABSPATH' ) || exit;

final class Signature {

	public static function redact_request_args( $request_args ) {
		if ( ! is_array( $request_args ) ) {
			return $request_args;
		}
		if ( isset( $request_args['headers'] ) && is_array( $request_args['headers'] ) ) {
			foreach ( [ 'X-LINE-Authorization', 'X-LINE-Authorization-Nonce', 'X-LINE-ChannelId' ] as $h ) {
				if ( isset( $request_args['headers'][ $h ] ) ) {
					$request_args['headers'][ $h ] = '[REDACTED]';
				}
			}
		}
		return $request_args;
	}

	public static function json_custom_decode( string $json ) {
		if ( version_compare( PHP_VERSION, '5.4.0', '>=' ) ) {
			return json_decode( $json, false, 512, JSON_BIGINT_AS_STRING );
		}
		return json_decode( preg_replace( '/:\s?(\d{14,})/', ': "${1}"', $json ) );
	}

	public static function generate_signature( string $channel_secret, string $url, string $request_body, string $nonce, string $method = 'POST' ): string {
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		// v3 spec: GET signs query, POST signs body
		$payload = 'GET' === strtoupper( $method ) ? (string) wp_parse_url( $url, PHP_URL_QUERY ) : $request_body;
		$data    = $channel_secret . $url_path . $payload . $nonce;
		return base64_encode( hash_hmac( \Moksafowo_LinePay_Const::AUTH_ALGRO, $data, $channel_secret, true ) );
	}

	public static function generate_request_time(): string {
		// PHP 8.2 strict: microtime(true) is float — must cast before explode
		$parts    = explode( '.', (string) microtime( true ) );
		$fraction = $parts[1] ?? '0';
		return gmdate( \Moksafowo_LinePay_Const::REQUEST_TIME_FORMAT ) . $fraction;
	}

	public static function callback_token( $order_id, string $request_type ): string {
		// Hard-fail rather than fall back to a public constant — key makes moksafowo_token un-forgeable
		$source = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY
			: ( defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '' );
		if ( '' === $source ) {
			throw new \RuntimeException( 'LinePay callback_token: AUTH_KEY (or SECURE_AUTH_KEY) is not defined in wp-config.php.' );
		}
		return hash_hmac( 'sha256', $order_id . '|' . $request_type, $source );
	}
}
