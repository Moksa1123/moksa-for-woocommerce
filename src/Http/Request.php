<?php

declare( strict_types=1 );

namespace Moksafowo\Http;

defined( 'ABSPATH' ) || exit;

final class Request {

	public static function post( string $url, array $body, array $headers = [], string $content_type = 'json', int $timeout = 30 ): Response {
		$args = self::common_args( $headers, $content_type, $timeout );
		if ( 'json' === $content_type ) {
			$args['body'] = wp_json_encode( $body );
		} else {
			$args['body'] = http_build_query( $body );
		}

		$resp = wp_remote_post( $url, $args );

		return self::handle( $url, $resp );
	}

	public static function get( string $url, array $query = [], array $headers = [], int $timeout = 30 ): Response {
		if ( $query !== [] ) {
			$url = add_query_arg( $query, $url );
		}
		$args = self::common_args( $headers, 'json', $timeout );
		$resp = wp_remote_get( $url, $args );

		return self::handle( $url, $resp );
	}

	/**
	 * 對外 API 呼叫用的 User-Agent。藍新（ccore／core.newebpay.com）的 WAF 會把 WordPress 預設的
	 * 「WordPress/x.y; 網址」擋成 403，直接用 wp_remote_* 的地方也要帶這個。
	 */
	public static function user_agent(): string {
		return sprintf( 'Moksa/%s WordPress/%s', MOKSAFOWO_VERSION, get_bloginfo( 'version' ) );
	}

	private static function common_args( array $headers, string $content_type, int $timeout ): array {
		$default_headers = [
			'Accept'     => 'application/json',
			'User-Agent' => self::user_agent(),
		];
		if ( 'json' === $content_type ) {
			$default_headers['Content-Type'] = 'application/json; charset=utf-8';
		} else {
			$default_headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8';
		}

		return [
			'timeout'     => $timeout,
			'redirection' => 3,
			'sslverify'   => true,
			'headers'     => array_merge( $default_headers, $headers ),
		];
	}

	private static function handle( string $url, $resp ): Response {
		if ( is_wp_error( $resp ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'HTTP transport error to %s: %s', $url, $resp->get_error_message() ) )
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );

		return new Response( $code, $body, wp_remote_retrieve_headers( $resp ) );
	}
}
