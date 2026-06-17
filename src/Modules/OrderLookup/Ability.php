<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress Abilities API — mo-ectools/find-order-by-number。
 *
 * 唯讀 ability：用發票 / 物流 / 金流號碼查訂單。供 WP 命令面板、REST、
 * 以及（商家開啟 WC MCP 後）AI 共用。需 WP 6.9+ 核心 Abilities API。
 */
final class Ability {

	const ABILITY = 'mo-ectools/find-order-by-number';

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'mo-ectools' ) ) {
			return;
		}
		wp_register_ability_category(
			'mo-ectools',
			[
				'label'       => __( 'Moksa for WooCommerce', 'mo-ectools' ),
				'description' => __( '台灣金流 / 物流 / 電子發票能力', 'mo-ectools' ),
			]
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			self::ABILITY,
			[
				'label'               => __( '依號碼查訂單', 'mo-ectools' ),
				'description'         => __( '用電子發票號碼 / 物流單號 / 金流交易序號查詢符合的 WooCommerce 訂單，回傳訂單編號、買家、狀態與後台連結。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'number' => [
							'type'        => 'string',
							'description' => __( '要查的發票 / 物流 / 金流號碼', 'mo-ectools' ),
						],
					],
					'required'             => [ 'number' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'       => [ 'type' => 'integer' ],
							'number'   => [ 'type' => 'string' ],
							'name'     => [ 'type' => 'string' ],
							'status'   => [ 'type' => 'string' ],
							'total'    => [ 'type' => 'string' ],
							'matched'  => [ 'type' => 'string' ],
							'edit_url' => [ 'type' => 'string' ],
						],
					],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_shop_orders' );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'mo-ectools/query-orders',
			[
				'label'               => __( '查訂單數量 / 狀態', 'mo-ectools' ),
				'description'         => __( '查詢訂單數量與各狀態筆數分布,或列出某狀態的訂單。常見狀態 slug:processing(處理中 / 待出貨)、pending(待付款)、on-hold(保留中)、completed(已完成)、cancelled(已取消)、refunded(已退款)。不給 status 會回各狀態的筆數分布。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					// status 設必填 + 用 all 當哨值:確保 AI 一定帶物件(不會傳 null 被驗證擋),
					// 且 type 維持單一 object(Gemini 不接受 type 為陣列 union)。
					'type'                 => 'object',
					'properties'           => [
						'status' => [
							'type'        => 'string',
							'description' => __( 'WooCommerce 訂單狀態 slug;用 all 取得各狀態筆數分布,或指定如 processing(處理中 / 待出貨)、pending(待付款)、completed(已完成)、cancelled(已取消)、refunded(已退款)', 'mo-ectools' ),
						],
					],
					'required'             => [ 'status' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'total'     => [ 'type' => 'integer' ],
						'breakdown' => [ 'type' => 'array' ],
						'orders'    => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ QueryOrders::class, 'run' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_shop_orders' );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	/**
	 * @param mixed $input ability 輸入（{ number: string }）。
	 * @return array<int, array<string, mixed>>
	 */
	public static function execute( $input ): array {
		$number = is_array( $input ) && isset( $input['number'] ) ? (string) $input['number'] : '';
		return OrderNumberLookup::resolve( $number, 10 );
	}

	/**
	 * 把本外掛的 ability 納入 WooCommerce 的 MCP server（非 woocommerce/ 前綴需明確納入）。
	 *
	 * @param mixed  $include    WC 是否納入該 ability。
	 * @param string $ability_id ability id。
	 * @return mixed
	 */
	public static function include_in_mcp( $include, $ability_id ) {
		if ( is_string( $ability_id ) && 0 === strpos( $ability_id, 'mo-ectools/' ) ) {
			return true;
		}
		return $include;
	}
}
