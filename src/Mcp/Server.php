<?php

declare( strict_types=1 );

namespace Moksafowo\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * 合規的 stateless MCP（Model Context Protocol）Streamable HTTP server。
 *
 * 把本外掛已註冊的 WP Abilities 包成 MCP tool 直接吐出,讓標準 MCP client
 * (mcp-remote / Claude 內建 HTTP client)免橋接器直連。設計重點:
 * - Stateless:initialize 不發 Mcp-Session-Id、後續請求也不要求(規範允許)。
 * - 合規 tool 物件:name / description / inputSchema / outputSchema / annotations,
 *   無多餘 top-level 欄位。annotations 用規範駝峰鍵(readOnlyHint…)。
 * - outputSchema 一律 object(非 object 自動包一層,結果同步包,避免整批被拒)。
 * - tools/list 快取(transient,key 含版本 + 破壞性暴露狀態)。
 * - 認證:permission_callback 要求 edit_shop_orders(走 WP 應用程式密碼 Basic auth
 *   或 cookie+nonce);破壞性能力預設不暴露(同 MCP gate)。
 */
final class Server {

	const NS       = 'mo-ectools/v1';
	const ROUTE    = '/mcp';
	const PROTOCOL = '2025-06-18';

	public static function enabled(): bool {
		return 'yes' === get_option( 'moksafowo_ai_mcp_server_enabled', 'no' );
	}

	public static function endpoint_url(): string {
		return rest_url( self::NS . self::ROUTE );
	}

	public static function register(): void {
		if ( ! self::enabled() ) {
			return;
		}
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle' ),
					'permission_callback' => array( self::class, 'authorize' ),
				),
				// 本 server 不提供 SSE stream / session 終止 → 規範要求對 GET/DELETE 回 405。
				array(
					'methods'             => 'GET, DELETE',
					'callback'            => array( self::class, 'method_not_allowed' ),
					'permission_callback' => array( self::class, 'authorize' ),
				),
			)
		);
	}

	public static function authorize(): bool {
		return current_user_can( 'edit_shop_orders' );
	}

	public static function method_not_allowed(): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 405 );
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	/**
	 * @param \WP_REST_Request $request JSON-RPC 請求(單筆或 batch）。
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array() === $body ) {
			return new \WP_REST_Response( self::rpc_error( null, -32700, 'Parse error' ), 200 );
		}

		if ( array_is_list( $body ) ) {
			if ( count( $body ) > 50 ) {
				return new \WP_REST_Response( self::rpc_error( null, -32600, 'Batch too large' ), 200 );
			}
			$out = array();
			foreach ( $body as $msg ) {
				$res = self::dispatch( is_array( $msg ) ? $msg : array() );
				if ( null !== $res ) {
					$out[] = $res;
				}
			}
			return new \WP_REST_Response( array() === $out ? null : $out, array() === $out ? 202 : 200 );
		}

		$res = self::dispatch( $body );
		if ( null === $res ) {
			return new \WP_REST_Response( null, 202 ); // notification → 無回應。
		}
		return new \WP_REST_Response( $res, 200 );
	}

	/**
	 * @param array<string,mixed> $msg 單筆 JSON-RPC 訊息。
	 * @return array<string,mixed>|null null = notification(不回應）。
	 */
	private static function dispatch( array $msg ): ?array {
		$id      = $msg['id'] ?? null;
		$method  = isset( $msg['method'] ) ? (string) $msg['method'] : '';
		$params  = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();
		$is_note = ! array_key_exists( 'id', $msg );

		switch ( $method ) {
			case 'initialize':
				// 回本 server 支援的版本(規範:不應直接 echo client 版本）。
				return self::rpc_result(
					$id,
					array(
						'protocolVersion' => self::PROTOCOL,
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array(
							'name'    => 'mo-ectools',
							'title'   => 'Moksa 電商工具',
							'version' => MOKSAFOWO_VERSION,
						),
					)
				);

			case 'notifications/initialized':
			case 'notifications/cancelled':
				return null;

			case 'ping':
				return self::rpc_result( $id, (object) array() );

			case 'tools/list':
				return self::rpc_result( $id, array( 'tools' => self::tools() ) );

			case 'tools/call':
				return self::call_tool( $id, $params );

			default:
				return $is_note ? null : self::rpc_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * 本外掛要暴露的 abilities（name => WP_Ability),套 mcp.public + 破壞性 gate。
	 *
	 * @return array<string, object>
	 */
	private static function abilities(): array {
		$out = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $out;
		}
		$expose_destructive = 'yes' === get_option( 'moksafowo_ai_mcp_expose_destructive', 'no' );
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$name = (string) $ability->get_name();
			if ( 0 !== strpos( $name, 'mo-ectools/' ) ) {
				continue;
			}
			$meta = (array) $ability->get_meta();
			$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
			if ( array_key_exists( 'public', $mcp ) && ! $mcp['public'] ) {
				continue;
			}
			$ann = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( ! empty( $ann['destructive'] ) && ! $expose_destructive ) {
				continue;
			}
			$out[ $name ] = $ability;
		}
		return $out;
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	private static function tools(): array {
		$key    = 'moksafowo_mcp_tools_' . md5( MOKSAFOWO_VERSION . '|' . get_option( 'moksafowo_ai_mcp_expose_destructive', 'no' ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$tools = array();
		foreach ( self::abilities() as $ability ) {
			$tools[] = self::tool_schema( $ability );
		}
		set_transient( $key, $tools, DAY_IN_SECONDS );
		return $tools;
	}

	/**
	 * @param object $ability WP_Ability。
	 * @return array<string,mixed>
	 */
	private static function tool_schema( $ability ): array {
		$name  = (string) $ability->get_name();
		$meta  = (array) $ability->get_meta();
		$ann   = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$input = $ability->get_input_schema();
		$input = is_array( $input ) && ! empty( $input )
			? $input
			: array(
				'type'       => 'object',
				'properties' => (object) array(),
			);

		$tool = array(
			'name'        => self::tool_name( $name ),
			'description' => (string) $ability->get_description(),
			'inputSchema' => $input,
			'annotations' => array(
				'title'           => (string) $ability->get_label(),
				'readOnlyHint'    => ! empty( $ann['readonly'] ),
				'destructiveHint' => ! empty( $ann['destructive'] ),
				'idempotentHint'  => ! empty( $ann['idempotent'] ),
				'openWorldHint'   => false,
			),
		);

		$out = $ability->get_output_schema();
		if ( is_array( $out ) && ! empty( $out ) ) {
			$wrap_key             = self::wrap_key( $out );
			$tool['outputSchema'] = null === $wrap_key
				? $out
				: array(
					'type'       => 'object',
					'properties' => array( $wrap_key => $out ),
					'required'   => array( $wrap_key ),
				);
		}
		return $tool;
	}

	/**
	 * @param mixed                $id     JSON-RPC id。
	 * @param array<string,mixed>  $params { name, arguments }。
	 * @return array<string,mixed>
	 */
	private static function call_tool( $id, array $params ): array {
		$tool_name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args      = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$abilities    = self::abilities();
		$ability_name = self::ability_name( $tool_name );
		if ( '' === $ability_name || ! isset( $abilities[ $ability_name ] ) ) {
			return self::rpc_error( $id, -32602, 'Unknown tool: ' . $tool_name );
		}
		$ability = $abilities[ $ability_name ];

		$perm = $ability->check_permissions( $args );
		if ( is_wp_error( $perm ) || false === $perm ) {
			return self::tool_error( $id, __( '權限不足。', 'moksa-for-woocommerce' ) );
		}

		try {
			$result = $ability->execute( $args );
		} catch ( \Throwable $e ) {
			$message = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
				? $e->getMessage()
				: __( '工具執行失敗,請稍後再試。', 'moksa-for-woocommerce' );
			return self::tool_error( $id, $message );
		}
		if ( is_wp_error( $result ) ) {
			return self::tool_error( $id, $result->get_error_message() );
		}

		$wrap_key   = self::wrap_key( $ability->get_output_schema() );
		$structured = null !== $wrap_key ? array( $wrap_key => $result ) : $result;

		return self::rpc_result(
			$id,
			array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => (string) wp_json_encode( $structured, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
					),
				),
				'structuredContent' => $structured,
				'isError'           => false,
			)
		);
	}

	/**
	 * 非 object 的 output schema 要包一層才合規;回傳包裝鍵(null = 不用包）。
	 *
	 * @param mixed $out output schema。
	 */
	private static function wrap_key( $out ): ?string {
		if ( ! is_array( $out ) || empty( $out ) ) {
			return null;
		}
		return ( isset( $out['type'] ) && 'object' === $out['type'] ) ? null : 'results';
	}

	private static function tool_name( string $ability ): string {
		return str_replace( '/', '-', $ability );
	}

	private static function ability_name( string $tool ): string {
		if ( 0 !== strpos( $tool, 'mo-ectools-' ) ) {
			return '';
		}
		return 'mo-ectools/' . substr( $tool, strlen( 'mo-ectools-' ) );
	}

	/**
	 * @param mixed                $id     JSON-RPC id。
	 * @param array<string,mixed>|object $result 結果。
	 * @return array<string,mixed>
	 */
	private static function rpc_result( $id, $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * @param mixed $id JSON-RPC id。
	 * @return array<string,mixed>
	 */
	private static function rpc_error( $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * tool 執行錯誤 → 規範要求回正常 result 但 isError=true（非 JSON-RPC error）。
	 *
	 * @param mixed $id JSON-RPC id。
	 * @return array<string,mixed>
	 */
	private static function tool_error( $id, string $message ): array {
		return self::rpc_result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $message,
					),
				),
				'isError' => true,
			)
		);
	}
}
