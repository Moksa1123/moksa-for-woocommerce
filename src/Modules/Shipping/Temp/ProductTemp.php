<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Temp;

use Moksafowo\Product\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class ProductTemp {

	public const NORMAL       = 1;
	public const REFRIGERATED = 2;
	public const FROZEN       = 3;

	public static function for_product( ?\WC_Product $product ): int {
		if ( ! $product instanceof \WC_Product ) {
			return self::NORMAL;
		}
		$temp = (int) $product->get_meta( Keys::PRODUCT_TEMP, true );
		if ( 0 === $temp && 'variation' === $product->get_type() ) {
			$parent_id = (int) $product->get_parent_id();
			if ( $parent_id > 0 ) {
				$parent = wc_get_product( $parent_id );
				if ( $parent instanceof \WC_Product ) {
					$temp = (int) $parent->get_meta( Keys::PRODUCT_TEMP, true );
				}
			}
		}
		if ( ! in_array( $temp, [ self::NORMAL, self::REFRIGERATED, self::FROZEN ], true ) ) {
			$temp = self::NORMAL;
		}
		return $temp;
	}


	public static function temps_in_package( array $package ): array {
		$temps    = [];
		$contents = $package['contents'] ?? [];
		if ( ! is_array( $contents ) ) {
			return [ self::NORMAL ];
		}
		foreach ( $contents as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof \WC_Product ) {
				$temps[ self::for_product( $product ) ] = true;
			}
		}
		if ( empty( $temps ) ) {
			return [ self::NORMAL ];
		}
		$keys = array_keys( $temps );
		sort( $keys );
		return array_map( 'intval', $keys );
	}

	public static function temps_in_cart(): array {
		if ( ! function_exists( 'WC' ) ) {
			return [ self::NORMAL ];
		}
		$cart = WC()->cart;
		if ( null === $cart ) {
			return [ self::NORMAL ];
		}
		$temps = [];
		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( $product instanceof \WC_Product ) {
				$temps[ self::for_product( $product ) ] = true;
			}
		}
		if ( empty( $temps ) ) {
			return [ self::NORMAL ];
		}
		$keys = array_keys( $temps );
		sort( $keys );
		return array_map( 'intval', $keys );
	}

	public static function group_order_items( \WC_Order $order, array $supported_temps = [ self::NORMAL, self::REFRIGERATED, self::FROZEN ] ): array {
		$groups   = [];
		$fallback = in_array( self::NORMAL, $supported_temps, true ) ? self::NORMAL : (int) reset( $supported_temps );

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$temp    = self::for_product( $product );
			if ( ! in_array( $temp, $supported_temps, true ) ) {
				$temp = $fallback;
			}
			$groups[ $temp ][ (int) $item_id ] = $item;
		}

		ksort( $groups );
		return $groups;
	}

	public static function label( int $temp ): string {
		return match ( $temp ) {
			self::REFRIGERATED => __( '冷藏', 'moksa-for-woocommerce' ),
			self::FROZEN       => __( '冷凍', 'moksa-for-woocommerce' ),
			default            => __( '常溫', 'moksa-for-woocommerce' ),
		};
	}

	public static function options( bool $include_inherit = false ): array {
		$options = [];
		if ( $include_inherit ) {
			$options[''] = __( '繼承父商品設定', 'moksa-for-woocommerce' );
		}
		$options[ self::NORMAL ]       = self::label( self::NORMAL );
		$options[ self::REFRIGERATED ] = self::label( self::REFRIGERATED );
		$options[ self::FROZEN ]       = self::label( self::FROZEN );
		return $options;
	}
}
