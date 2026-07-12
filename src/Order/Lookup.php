<?php

declare( strict_types=1 );

namespace Moksafowo\Order;

defined( 'ABSPATH' ) || exit;

final class Lookup {

	public static function by_meta( string $meta_key, string $meta_value ): ?int {
		if ( '' === $meta_value || '' === $meta_key ) {
			return null;
		}
		$found = wc_get_orders(
			[
				'limit'      => 1,
				'return'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
				'meta_key'   => $meta_key,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
				'meta_value' => $meta_value,
			]
		);
		if ( empty( $found ) ) {
			return null;
		}
		return (int) $found[0];
	}

	private function __construct() {}
}
