<?php

declare( strict_types=1 );

namespace Moksafowo\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * MCP 的 resources 與 prompts。
 *
 * tools 是「讓 client 做事」，這兩個原語是「讓 client 先看懂這家店」：
 * - resources：一次讀完的店況快照（啟用了哪些金流物流發票、最近賣得怎樣、有哪些運送方式）。
 *   沒有這個，client 每次都要靠好幾輪 tool call 才拼得出上下文。
 * - prompts：把常見作業寫成樣板（每日出貨、對帳、發票檢查），
 *   商家不用自己想怎麼問。
 *
 * 兩者都唯讀，且一律過 capability —— 這裡吐的是營運資料。
 */
final class Catalog {

	private const CAP = 'manage_woocommerce';

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public static function resources(): array {
		return [
			[
				'uri'         => 'moksafowo://store/setup',
				'name'        => 'store-setup',
				'title'       => __( 'What this store has switched on', 'moksa-for-woocommerce' ),
				'description' => __( 'Which payment, shipping and e-invoice providers are enabled, and whether each is in test mode. Read this first to know what the store can actually do.', 'moksa-for-woocommerce' ),
				'mimeType'    => 'application/json',
			],
			[
				'uri'         => 'moksafowo://store/shipping-methods',
				'name'        => 'shipping-methods',
				'title'       => __( 'Shipping methods on offer', 'moksa-for-woocommerce' ),
				'description' => __( 'The shipping methods customers can choose, by zone, including which are convenience store pickup.', 'moksa-for-woocommerce' ),
				'mimeType'    => 'application/json',
			],
			[
				'uri'         => 'moksafowo://store/sales-summary',
				'name'        => 'sales-summary',
				'title'       => __( 'Sales over the last 30 days', 'moksa-for-woocommerce' ),
				'description' => __( 'Order counts and revenue for the last 30 days, broken down by status.', 'moksa-for-woocommerce' ),
				'mimeType'    => 'application/json',
			],
		];
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function read( string $uri ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_mcp_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}

		switch ( $uri ) {
			case 'moksafowo://store/setup':
				$data = \Moksafowo\Modules\OrderLookup\PluginSettings::execute( [] );
				break;
			case 'moksafowo://store/shipping-methods':
				$data = \Moksafowo\Modules\OrderLookup\ShippingZoneOps::list_zones( [] );
				break;
			case 'moksafowo://store/sales-summary':
				$data = \Moksafowo\Modules\OrderLookup\SalesSummary::execute( [ 'days' => 30 ] );
				break;
			default:
				/* translators: %s: resource URI */
				return new \WP_Error( 'moksafowo_mcp_no_resource', sprintf( __( 'Unknown resource: %s', 'moksa-for-woocommerce' ), $uri ) );
		}

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return [
			'contents' => [
				[
					'uri'      => $uri,
					'mimeType' => 'application/json',
					'text'     => (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
				],
			],
		];
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public static function prompts(): array {
		return [
			[
				'name'        => 'daily-shipping',
				'title'       => __( 'Daily shipping run', 'moksa-for-woocommerce' ),
				'description' => __( 'Work through today\'s paid orders: which still need a shipment created, which are ready to print, and which are waiting at the store.', 'moksa-for-woocommerce' ),
				'arguments'   => [],
			],
			[
				'name'        => 'reconcile-payments',
				'title'       => __( 'Check payments against the provider', 'moksa-for-woocommerce' ),
				'description' => __( 'Find orders where this site and the payment provider might disagree, and look each one up at the provider.', 'moksa-for-woocommerce' ),
				'arguments'   => [
					[
						'name'        => 'days',
						'description' => __( 'How many days back to look. Defaults to 7.', 'moksa-for-woocommerce' ),
						'required'    => false,
					],
				],
			],
			[
				'name'        => 'invoice-check',
				'title'       => __( 'Find orders missing an e-invoice', 'moksa-for-woocommerce' ),
				'description' => __( 'List completed orders that have no e-invoice number yet, so none are missed before the filing deadline.', 'moksa-for-woocommerce' ),
				'arguments'   => [
					[
						'name'        => 'days',
						'description' => __( 'How many days back to look. Defaults to 30.', 'moksa-for-woocommerce' ),
						'required'    => false,
					],
				],
			],
			[
				'name'        => 'change-pickup-store',
				'title'       => __( 'Move an order to another store', 'moksa-for-woocommerce' ),
				'description' => __( 'A customer wants to collect the parcel somewhere else: change the pickup store and, if a shipment already exists, delete it and create it again.', 'moksa-for-woocommerce' ),
				'arguments'   => [
					[
						'name'        => 'order',
						'description' => __( 'The order number.', 'moksa-for-woocommerce' ),
						'required'    => true,
					],
					[
						'name'        => 'store_id',
						'description' => __( 'The new store number.', 'moksa-for-woocommerce' ),
						'required'    => true,
					],
				],
			],
		];
	}

	/**
	 * @param string              $name Prompt name.
	 * @param array<string,mixed> $args Prompt arguments from the client.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function prompt( string $name, array $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_mcp_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}

		$days  = isset( $args['days'] ) ? max( 1, min( 365, (int) $args['days'] ) ) : 0;
		$order = isset( $args['order'] ) ? preg_replace( '/[^0-9]/', '', (string) $args['order'] ) : '';
		$store = isset( $args['store_id'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $args['store_id'] ) : '';

		switch ( $name ) {
			case 'daily-shipping':
				$text = __( 'Go through the orders that are paid but not shipped yet. For each one tell me whether a shipment has been created, and if not create it. Then tell me which ones are ready for their labels to be printed. Use list-orders, list-shipments and create-shipment. Do not print anything without asking me first.', 'moksa-for-woocommerce' );
				break;

			case 'reconcile-payments':
				$text = sprintf(
					/* translators: %d: number of days */
					__( 'Look at orders from the last %d days that are still pending or on hold even though the customer may have paid. For each one, look the payment up at the provider with query-payment and tell me where this site and the provider disagree. Do not change any order status — just report.', 'moksa-for-woocommerce' ),
					$days > 0 ? $days : 7
				);
				break;

			case 'invoice-check':
				$text = sprintf(
					/* translators: %d: number of days */
					__( 'List completed orders from the last %d days that still have no e-invoice number, using get-order-details to confirm each one. Show them as a list with the order number, date and amount. Do not issue any invoices — just tell me which are missing.', 'moksa-for-woocommerce' ),
					$days > 0 ? $days : 30
				);
				break;

			case 'change-pickup-store':
				if ( '' === $order || '' === $store ) {
					return new \WP_Error( 'moksafowo_mcp_prompt_args', __( 'This prompt needs both an order number and a store number.', 'moksa-for-woocommerce' ) );
				}
				$text = sprintf(
					/* translators: 1: order number, 2: store number */
					__( 'The customer on order %1$s wants to collect at store %2$s instead. Change the pickup store. Then check with list-shipments whether a shipment already exists — if it does, delete it and create it again so the label goes to the new store. Walk me through each step and stop at every confirmation.', 'moksa-for-woocommerce' ),
					$order,
					$store
				);
				break;

			default:
				/* translators: %s: prompt name */
				return new \WP_Error( 'moksafowo_mcp_no_prompt', sprintf( __( 'Unknown prompt: %s', 'moksa-for-woocommerce' ), $name ) );
		}

		return [
			'messages' => [
				[
					'role'    => 'user',
					'content' => [
						'type' => 'text',
						'text' => $text,
					],
				],
			],
		];
	}
}
