<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Compatibility;

use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

final class Hpos {

	public static function enabled(): bool {
		return class_exists( OrderUtil::class )
			&& OrderUtil::custom_orders_table_usage_is_enabled();
	}

	public static function order_screen_id(): string {
		if ( self::enabled() && function_exists( 'wc_get_page_screen_id' ) ) {
			return wc_get_page_screen_id( 'shop-order' );
		}
		return 'shop_order';
	}

	public static function subscription_screen_id(): string {
		if ( self::enabled() && function_exists( 'wc_get_page_screen_id' ) ) {
			return wc_get_page_screen_id( 'shop-subscription' );
		}
		return 'shop_subscription';
	}
}
