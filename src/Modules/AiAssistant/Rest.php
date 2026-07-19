<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Moksa AI REST:/ai-chat(對話 → Agent)+ /ai-confirm(破壞性動作人工確認後執行)。
 */
final class Rest {

	const REST_NAMESPACE = 'mo-ectools/v1';
	const CONFIRM_PREFIX = 'moksafowo_ai_confirm_';

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
					'history' => [
						'type'     => 'array',
						'required' => false,
						'default'  => [],
					],
				],
				'callback'            => [ self::class, 'chat' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai-confirm',
			[
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => [
					'token' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
				'callback'            => [ self::class, 'confirm' ],
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request 請求。
	 * @return \WP_REST_Response
	 */
	public static function chat( \WP_REST_Request $request ): \WP_REST_Response {
		$message = trim( (string) $request->get_param( 'message' ) );
		if ( '' === $message ) {
			return rest_ensure_response( [ 'reply' => '' ] );
		}

		$history = self::sanitize_history( $request->get_param( 'history' ) );

		$result = Agent::run( $message, Config::abilities(), Config::system_instruction(), $history );

		$type = $result['type'] ?? 'text';
		if ( 'confirm' === $type ) {
			return rest_ensure_response(
				[
					'confirm' => [
						'token'   => (string) ( $result['token'] ?? '' ),
						'summary' => (string) ( $result['summary'] ?? '' ),
					],
				]
			);
		}
		if ( 'error' === $type ) {
			return rest_ensure_response( [ 'error' => (string) ( $result['message'] ?? '' ) ] );
		}
		return rest_ensure_response( [ 'reply' => (string) ( $result['reply'] ?? '' ) ] );
	}

	/**
	 * 消毒前端帶來的對話歷史(上下文記憶用)。只取最近 10 則、每則裁長。
	 *
	 * @param mixed $raw 前端傳的 history 陣列。
	 * @return array<int, array{role:string, text:string}>
	 */
	private static function sanitize_history( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			$role = ( isset( $turn['role'] ) && 'assistant' === $turn['role'] ) ? 'assistant' : 'user';
			$text = isset( $turn['text'] ) ? trim( sanitize_textarea_field( (string) $turn['text'] ) ) : '';
			if ( '' === $text ) {
				continue;
			}
			if ( mb_strlen( $text ) > 2000 ) {
				$text = mb_substr( $text, 0, 2000 );
			}
			$out[] = [
				'role' => $role,
				'text' => $text,
			];
		}
		return array_slice( $out, -10 );
	}

	/**
	 * 使用者按「確認執行」後,驗證一次性 token 並真正執行破壞性動作。
	 *
	 * @param \WP_REST_Request $request 請求。
	 * @return \WP_REST_Response
	 */
	public static function confirm( \WP_REST_Request $request ): \WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		$key   = self::CONFIRM_PREFIX . $token;
		$data  = get_transient( $key );

		if ( ! is_array( $data ) ) {
			return rest_ensure_response( [ 'error' => __( '確認已失效或不存在,請重新操作。', 'moksa-for-woocommerce' ) ] );
		}
		if ( (int) ( $data['user'] ?? 0 ) !== get_current_user_id() ) {
			return rest_ensure_response( [ 'error' => __( '權限不符,無法執行。', 'moksa-for-woocommerce' ) ] );
		}

		delete_transient( $key );

		$ability  = (string) ( $data['ability'] ?? '' );
		$handlers = Config::destructive_handlers();
		if ( ! isset( $handlers[ $ability ]['apply'] ) || ! is_callable( $handlers[ $ability ]['apply'] ) ) {
			return rest_ensure_response( [ 'error' => __( '不支援的操作。', 'moksa-for-woocommerce' ) ] );
		}

		$result = call_user_func( $handlers[ $ability ]['apply'], (array) ( $data['params'] ?? [] ) );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [ 'error' => $result->get_error_message() ] );
		}
		if ( is_array( $result ) && isset( $result['print_url'] ) ) {
			return rest_ensure_response(
				[
					'reply'     => (string) ( $result['message'] ?? '' ),
					'print_url' => esc_url_raw( (string) $result['print_url'] ),
				]
			);
		}
		return rest_ensure_response( [ 'reply' => (string) $result ] );
	}
}
