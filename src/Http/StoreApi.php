<?php

declare( strict_types=1 );

namespace Moksafowo\Http;

defined( 'ABSPATH' ) || exit;

/**
 * 這個請求是不是 WooCommerce Store API（區塊結帳、區塊購物車）。
 *
 * 不在 REQUEST_URI 裡找 "/wc/store/"（WC 的 is_store_api_request() 也是這樣）：查詢字串裡塞這段
 * 就能騙過。這裡讀 WP 解析後真正要派送的 REST 路由（rest_api_loaded 用的就是這個 query var），
 * 判斷結果跟實際執行的路由一致。
 */
final class StoreApi {

	public static function is_request(): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}
		$wp    = $GLOBALS['wp'] ?? null;
		$route = $wp instanceof \WP && isset( $wp->query_vars['rest_route'] ) ? (string) $wp->query_vars['rest_route'] : '';
		return str_starts_with( ltrim( $route, '/' ), 'wc/store/' );
	}

	private function __construct() {}
}
