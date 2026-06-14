<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單查號 REST endpoint — 供命令面板（Ctrl+K）即時查詢用。
 */
final class Rest {

	const REST_NAMESPACE = 'mo-ectools/v1';

	public static function register(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/order-lookup',
			[
				'methods'             => 'GET',
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_shop_orders' );
				},
				'args'                => [
					'number' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
				'callback'            => [ self::class, 'lookup' ],
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request REST 請求。
	 * @return \WP_REST_Response
	 */
	public static function lookup( \WP_REST_Request $request ): \WP_REST_Response {
		$term    = (string) $request->get_param( 'number' );
		$results = OrderNumberLookup::resolve( $term, 10 );

		$out = [];
		foreach ( $results as $order ) {
			$suffix = '' !== $order['matched']
				/* translators: %s: matched field label, e.g. 發票號碼 */
				? ' · ' . sprintf( __( '%s相符', 'mo-ectools' ), $order['matched'] )
				: '';
			$out[] = [
				'id'       => $order['id'],
				'label'    => sprintf(
					/* translators: 1: order number, 2: buyer name, 3: order status */
					__( '訂單 #%1$s · %2$s · %3$s', 'mo-ectools' ),
					$order['number'],
					$order['name'],
					$order['status']
				) . $suffix,
				'edit_url' => $order['edit_url'],
			];
		}

		return rest_ensure_response( $out );
	}
}
