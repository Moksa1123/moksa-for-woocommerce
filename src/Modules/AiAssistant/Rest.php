<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * 浮動對話窗用的 REST endpoint。收一句話 → Agent::run() → 回覆文字。
 */
final class Rest {

	const REST_NAMESPACE = 'mo-ectools/v1';

	public static function register(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/ai-chat',
			[
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return current_user_can( Config::CAP );
				},
				'args'                => [
					'message' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
				'callback'            => [ self::class, 'chat' ],
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request REST 請求。
	 * @return \WP_REST_Response
	 */
	public static function chat( \WP_REST_Request $request ): \WP_REST_Response {
		$message = trim( (string) $request->get_param( 'message' ) );
		if ( '' === $message ) {
			return rest_ensure_response( [ 'reply' => '' ] );
		}

		$result = Agent::run( $message, Config::abilities(), Config::system_instruction() );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'reply' => '',
				'error' => $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [ 'reply' => (string) $result ] );
	}
}
