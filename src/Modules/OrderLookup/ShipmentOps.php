<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

use Moksafowo\Modules\Shipping\Admin\ShipmentCreator;

defined( 'ABSPATH' ) || exit;

/**
 * 破壞性操作:建立託運單(向物流商建單取號)。依訂單運送方式(method id 前綴
 * moksafowo_<provider>_shipping_)路由到對應 provider 的 Operations\Create*::run()。
 * 建單會向物流商真實下單取號(不可逆),故走人工確認關卡。各 Create 類自帶冪等保護。
 */
final class ShipmentOps {

	const CAP = 'manage_woocommerce';

	/**
	 * @return array<string, array{0: class-string|null, 1: string}>
	 */
	private static function providers(): array {
		return ShipmentCreator::providers();
	}

	private static function order_from( $args ): ?\WC_Order {
		$ref   = is_array( $args ) && isset( $args['order'] ) ? (string) $args['order'] : '';
		$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
		$order = $id ? wc_get_order( $id ) : false;
		return ( $order && 'shop_order' === $order->get_type() ) ? $order : null;
	}

	private static function resolve_provider( \WC_Order $order ): string {
		return ShipmentCreator::provider_for( $order );
	}

	/**
	 * @return array{ok:bool, message?:string}
	 */
	private static function create_for( string $provider, \WC_Order $order ): array {
		unset( $provider );
		return ShipmentCreator::create( $order );
	}

	/**
	 * @param mixed $args { order: string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$order = self::order_from( $args );
		if ( ! $order ) {
			return new \WP_Error( 'moksafowo_ai_no_order', __( 'The order could not be found.', 'moksa-for-woocommerce' ) );
		}
		$provider = self::resolve_provider( $order );
		$map      = self::providers();
		if ( '' === $provider || ! isset( $map[ $provider ] ) ) {
			return new \WP_Error( 'moksafowo_ai_no_shipping', __( 'The shipping method on this order does not support creating a shipment automatically.', 'moksa-for-woocommerce' ) );
		}

		$existing = ShipmentCreator::shipment_ref( $order );
		$summary  = sprintf(
			/* translators: 1: order number, 2: provider name */
			__( 'Create a shipment for order #%1$s with %2$s.', 'moksa-for-woocommerce' ),
			$order->get_order_number(),
			$map[ $provider ][1]
		);
		if ( '' !== $existing ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: existing shipping number */
				__( '(Note: this order already has tracking number %s, so another shipment may be added.)', 'moksa-for-woocommerce' ),
				$existing
			);
		}

		return array(
			'order_id' => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'provider' => $provider,
			'summary'  => $summary,
		);
	}

	/**
	 * @param array<string,mixed> $params prepare() 的回傳。
	 * @return string|\WP_Error
	 */
	public static function apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$order    = wc_get_order( (int) ( $params['order_id'] ?? 0 ) );
		$provider = (string) ( $params['provider'] ?? '' );
		$map      = self::providers();
		if ( ! $order || ! isset( $map[ $provider ] ) ) {
			return new \WP_Error( 'moksafowo_ai_no_order', __( 'The order or the carrier could not be found.', 'moksa-for-woocommerce' ) );
		}

		$result = self::create_for( $provider, $order );
		if ( empty( $result['ok'] ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'moksafowo_ai_create_failed', sprintf( __( 'Could not create the shipment: %s', 'moksa-for-woocommerce' ), (string) ( $result['message'] ?? '' ) ) );
		}

		$ship_no = SearchableKeys::field_value( wc_get_order( $order->get_id() ), 'shipping' );
		$suffix  = '';
		if ( '' !== $ship_no ) {
			/* translators: %s: shipping number */
			$suffix = sprintf( __( ', tracking number %s', 'moksa-for-woocommerce' ), $ship_no );
		}
		return sprintf(
			/* translators: 1: order number, 2: shipping number suffix(可能為空) */
			__( '✅ A shipment was created for order #%1$s%2$s.', 'moksa-for-woocommerce' ),
			$order->get_order_number(),
			$suffix
		);
	}

	const MAX_BATCH = 30;

	/**
	 * @param mixed $raw array 或逗號 / 空白分隔字串。
	 * @return string[]
	 */
	private static function normalize_refs( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,，、]+/u', $raw ) ?: array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$out[ $item ] = $item;
			}
		}
		return array_slice( array_values( $out ), 0, self::MAX_BATCH );
	}

	/**
	 * @param mixed $args { orders: array|string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function batch_prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$refs = self::normalize_refs( is_array( $args ) ? ( $args['orders'] ?? '' ) : '' );
		if ( empty( $refs ) ) {
			return new \WP_Error( 'moksafowo_ai_no_orders', __( 'No orders were given to create shipments for.', 'moksa-for-woocommerce' ) );
		}
		$map         = self::providers();
		$found       = array();
		$unsupported = array();
		foreach ( $refs as $ref ) {
			$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
			$order = $id ? wc_get_order( $id ) : false;
			if ( ! $order || 'shop_order' !== $order->get_type() ) {
				$unsupported[] = $ref;
				continue;
			}
			$provider = self::resolve_provider( $order );
			if ( '' === $provider || ! isset( $map[ $provider ] ) ) {
				$unsupported[] = '#' . $order->get_order_number();
				continue;
			}
			$found[ $order->get_id() ] = array(
				'order_id' => $order->get_id(),
				'number'   => (string) $order->get_order_number(),
				'provider' => $provider,
			);
		}
		if ( empty( $found ) ) {
			return new \WP_Error( 'moksafowo_ai_no_orders', __( 'None of the orders can have a shipment created; the shipping method must belong to a supported carrier.', 'moksa-for-woocommerce' ) );
		}

		$orders  = array_values( $found );
		$numbers = implode( ' ', array_map( static fn( $o ) => '#' . $o['number'], $orders ) );
		$summary = sprintf(
			/* translators: 1: count, 2: order numbers */
			__( 'Create shipments for %1$d orders (%2$s).', 'moksa-for-woocommerce' ),
			count( $orders ),
			$numbers
		);
		if ( ! empty( $unsupported ) ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: skipped refs */
				__( '(skipped because no shipment could be created: %s)', 'moksa-for-woocommerce' ),
				implode( '、', $unsupported )
			);
		}

		return array(
			'orders'  => $orders,
			'summary' => $summary,
		);
	}

	/**
	 * @param array<string,mixed> $params batch_prepare() 的回傳。
	 * @return string|\WP_Error
	 */
	public static function batch_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$orders = is_array( $params['orders'] ?? null ) ? $params['orders'] : array();
		$map    = self::providers();
		$done   = array();
		$failed = array();
		foreach ( $orders as $o ) {
			$order    = wc_get_order( (int) ( $o['order_id'] ?? 0 ) );
			$provider = (string) ( $o['provider'] ?? '' );
			if ( ! $order || ! isset( $map[ $provider ] ) ) {
				$failed[] = '#' . ( $o['number'] ?? '?' );
				continue;
			}
			$result = self::create_for( $provider, $order );
			if ( ! empty( $result['ok'] ) ) {
				$ship_no = SearchableKeys::field_value( wc_get_order( $order->get_id() ), 'shipping' );
				$done[]  = '#' . $order->get_order_number() . ( '' !== $ship_no ? ':' . $ship_no : '' );
			} else {
				$failed[] = '#' . $order->get_order_number();
			}
		}

		$msg = sprintf(
			/* translators: 1: success, 2: total, 3: order:tracking list */
			__( '✅ Shipments were created for %1$d of %2$d orders: %3$s.', 'moksa-for-woocommerce' ),
			count( $done ),
			count( $done ) + count( $failed ),
			implode( ' ', $done )
		);
		if ( ! empty( $failed ) ) {
			$msg .= ' ' . sprintf(
				/* translators: %s: failed order numbers */
				__( '⚠️ Failed: %s.', 'moksa-for-woocommerce' ),
				implode( ' ', $failed )
			);
		}
		return $msg;
	}
}
