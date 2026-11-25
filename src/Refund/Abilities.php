<?php

declare( strict_types=1 );

namespace Moksafowo\Refund;

use Moksafowo\Modules\Shared\Caps;

defined( 'ABSPATH' ) || exit;

/**
 * 退款只開兩個唯讀 Ability：待處理清單、單張訂單的退款狀態。
 * 不回卡號、金鑰、顧客個資；只回金額、狀態與說明。
 */
final class Abilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$meta = [
			'show_in_rest' => false,
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'mcp'          => [
				'public' => true,
				'type'   => 'tool',
			],
		];
		$can  = static fn(): bool => Caps::user_can( Caps::REFUND );
		$item = [ 'type' => 'object' ];

		wp_register_ability(
			'moksa-for-woocommerce/list-pending-refunds',
			[
				'label'               => __( 'List refunds that need review', 'moksa-for-woocommerce' ),
				'description'         => __( 'List orders with a refund that needs attention: a refund whose payment result could not be confirmed, or a refund whose e-invoice still has to be voided or given an allowance. Read-only; nothing is changed.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
					'default'              => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'orders' => [
							'type'  => 'array',
							'items' => $item,
						],
					],
				],
				'execute_callback'    => [ __CLASS__, 'list_pending' ],
				'permission_callback' => $can,
				'meta'                => $meta,
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/get-refund-status',
			[
				'label'               => __( 'Get the refund status of an order', 'moksa-for-woocommerce' ),
				'description'         => __( 'Show every refund on one order: amount, whether the money was refunded through the payment provider or manually, and what happened to the e-invoice (voided, allowance issued, waiting, failed). Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( 'The order number, for example 2896', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'order'   => [ 'type' => 'string' ],
						'items'   => [
							'type'  => 'array',
							'items' => $item,
						],
						'blocked' => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => [ __CLASS__, 'get_status' ],
				'permission_callback' => $can,
				'meta'                => $meta,
			]
		);
	}

	public static function list_pending(): array {
		$out = [];
		foreach ( PendingPage::open_orders( 50 ) as $order ) {
			$out[] = [
				'order' => (string) $order->get_order_number(),
				'items' => self::describe( PendingPage::items( $order ) ),
			];
		}
		return [ 'orders' => $out ];
	}

	/**
	 * @param mixed $args { order: string }。
	 * @return array|\WP_Error
	 */
	public static function get_status( $args ) {
		$id    = absint( preg_replace( '/[^0-9]/', '', (string) ( is_array( $args ) ? ( $args['order'] ?? '' ) : '' ) ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'moksafowo_no_order', __( 'The order could not be found.', 'moksa-for-woocommerce' ) );
		}
		return [
			'order'   => (string) $order->get_order_number(),
			'items'   => self::describe( PendingPage::items( $order ) ),
			'blocked' => (bool) Guard::unknown_list( $order ),
		];
	}

	private static function describe( array $items ): array {
		return array_map(
			static function ( array $i ): array {
				if ( 'unknown' === $i['kind'] ) {
					return [
						'amount' => $i['amount'],
						'status' => __( 'Payment result unconfirmed', 'moksa-for-woocommerce' ),
						'time'   => gmdate( 'c', $i['time'] ),
					];
				}
				return [
					'refund_id' => $i['refund_id'],
					'amount'    => $i['amount'],
					'payment'   => 'manual_done' === $i['payment'] ? __( 'Refunded manually', 'moksa-for-woocommerce' ) : __( 'Refunded through the payment provider', 'moksa-for-woocommerce' ),
					'invoice'   => PendingPage::state_label( $i['state'] ),
					'detail'    => trim( $i['detail'] . ' ' . $i['error'] ),
					'time'      => gmdate( 'c', $i['time'] ),
				];
			},
			$items
		);
	}
}
