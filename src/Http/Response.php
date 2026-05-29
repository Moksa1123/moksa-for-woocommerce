<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Http;

defined( 'ABSPATH' ) || exit;

final class Response {

	public function __construct(
	public readonly int $status,
	public readonly string $body,
	public readonly mixed $headers
	) {}

	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	public function json(): array {
		$decoded = json_decode( $this->body, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	public function form(): array {
		$out = [];
		parse_str( $this->body, $out );
		
		return $out;
	}

	public static function send_plain( int $status, string $body ): void {
		status_header( $status );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $body );
		exit;
	}
}
