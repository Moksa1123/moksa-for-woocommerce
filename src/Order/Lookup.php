<?php

declare( strict_types=1 );

namespace Moksafowo\Order;

defined( 'ABSPATH' ) || exit;

/**
 * 依訂單 meta 反查訂單（金流交易編號、物流單號…）。
 *
 * 照 WooCommerce 文件的「自訂查詢參數」做法：查詢時帶 self::QUERY_VAR，兩種訂單儲存各自轉換：
 * - HPOS：在 woocommerce_order_query_args 轉成 meta_query（HPOS 原生支援）。
 * - 傳統文章表：它的 get_wp_query_args() 會直接丟掉 query vars 裡的 meta_query（WC 9.2 起還會
 *   發 doing_it_wrong），所以參數留著，改在 woocommerce_order_data_store_cpt_get_orders_query
 *   併進交給 WP_Query 的 meta_query。兩邊都轉錯的話會變成「沒條件」，所以各自有單元測試。
 *
 * 注意：這只是換寫法，送進資料庫的 SQL 跟直接帶 meta_key／meta_value／meta_query 完全一樣，
 * 效能不變（HPOS 走 wc_orders_meta 的 meta_key_value 索引；傳統儲存走 postmeta 的 meta_key 索引）。
 *
 * 一律經過 self::orders() 查詢：它保證 filter 已掛上。filter 沒掛上的話，WC 會忽略不認得的
 * 參數，等於沒有條件，反查就會抓到隨便一張單。
 */
final class Lookup {

	public const QUERY_VAR = 'moksafowo_meta';

	/**
	 * 這次查詢的條件有沒有真的被轉換（filter 被別的外掛拿掉時會是 false）。
	 *
	 * @var bool
	 */
	private static bool $translated = false;

	public static function register(): void {
		if ( false === has_filter( 'woocommerce_order_query_args', [ self::class, 'translate' ] ) ) {
			add_filter( 'woocommerce_order_query_args', [ self::class, 'translate' ] );
		}
		if ( false === has_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ self::class, 'translate_cpt' ] ) ) {
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ self::class, 'translate_cpt' ], 10, 2 );
		}
		if ( false === has_action( 'pre_get_posts', [ self::class, 'translate_posts' ] ) ) {
			add_action( 'pre_get_posts', [ self::class, 'translate_posts' ] );
		}
	}

	/**
	 * WP_Query 版（其他外掛的自訂文章類型，例如 WPSubscription 的訂閱）：pre_get_posts 時把
	 * self::QUERY_VAR 併進 meta_query。get_posts() 的 suppress_filters 不影響 pre_get_posts。
	 */
	public static function translate_posts( \WP_Query $query ): void {
		$clause = $query->get( self::QUERY_VAR );
		if ( ! is_array( $clause ) || [] === $clause ) {
			return;
		}
		$meta   = $query->get( 'meta_query' );
		$meta   = is_array( $meta ) ? $meta : [];
		$meta[] = $clause;
		$query->set( 'meta_query', $meta );
		self::$translated = true;
		$query->set( self::QUERY_VAR, '' );
	}

	/**
	 * get_posts() 的包裝：保證 self::QUERY_VAR 會被轉換。
	 *
	 * @param array<string,mixed> $args 查詢參數，條件放在 self::QUERY_VAR。
	 * @return array<int,mixed>
	 */
	public static function posts( array $args ): array {
		self::register();
		self::$translated = false;
		$found            = get_posts( $args );
		return self::guard( $args, $found );
	}

	/**
	 * woocommerce_order_query_args：只在 HPOS 時轉換；傳統儲存交給 translate_cpt()。
	 *
	 * @param array<string,mixed> $args WC_Order_Query 的查詢參數。
	 * @return array<string,mixed>
	 */
	public static function translate( $args ) {
		if ( ! is_array( $args ) || ! self::hpos() ) {
			return $args;
		}
		return self::to_meta_query( $args );
	}

	/**
	 * 把 self::QUERY_VAR 轉成 meta_query 的一個子句（可以是單一條件，或帶 relation 的一組）。
	 *
	 * @param array<string,mixed> $args 查詢參數。
	 * @return array<string,mixed>
	 */
	public static function to_meta_query( array $args ): array {
		if ( ! isset( $args[ self::QUERY_VAR ] ) ) {
			return $args;
		}
		$clause = (array) $args[ self::QUERY_VAR ];
		unset( $args[ self::QUERY_VAR ] );
		if ( [] !== $clause ) {
			$args['meta_query'][] = $clause;
		}
		self::$translated = true;
		return $args;
	}

	/**
	 * woocommerce_order_data_store_cpt_get_orders_query：傳統文章表儲存，把條件併進交給 WP_Query 的參數。
	 *
	 * @param array<string,mixed> $wp_query_args 要交給 WP_Query 的參數。
	 * @param array<string,mixed> $query_vars    WC_Order_Query 的查詢參數。
	 * @return array<string,mixed>
	 */
	public static function translate_cpt( $wp_query_args, $query_vars ) {
		if ( ! is_array( $wp_query_args ) || ! is_array( $query_vars ) || ! isset( $query_vars[ self::QUERY_VAR ] ) ) {
			return $wp_query_args;
		}
		unset( $wp_query_args[ self::QUERY_VAR ] );
		$wp_query_args[ self::QUERY_VAR ] = $query_vars[ self::QUERY_VAR ];
		return self::to_meta_query( $wp_query_args );
	}

	private static function hpos(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * wc_get_orders() 的包裝：保證 self::QUERY_VAR 會被轉換。
	 *
	 * @param array<string,mixed> $args 查詢參數，條件放在 self::QUERY_VAR。
	 * @return array<int,mixed>
	 */
	public static function orders( array $args ): array {
		self::register();
		self::$translated = false;
		$found            = wc_get_orders( $args );
		return self::guard( $args, is_array( $found ) ? $found : [] );
	}

	/**
	 * 帶了條件、條件卻沒被轉換（filter 被拿掉、WC 改了流程）時，結果等於沒條件——寧可回空，也不回一張不相干的訂單。
	 *
	 * @param array<string,mixed> $args  查詢參數。
	 * @param array<int,mixed>    $found 查詢結果。
	 * @return array<int,mixed>
	 */
	private static function guard( array $args, array $found ): array {
		if ( isset( $args[ self::QUERY_VAR ] ) && [] !== (array) $args[ self::QUERY_VAR ] && ! self::$translated ) {
			\Moksafowo\Logging\Logger::warning( 'order-lookup', 'meta condition was not applied to the order query — returning no result', [ 'condition' => $args[ self::QUERY_VAR ] ] );
			return [];
		}
		return $found;
	}

	/** 依單一 meta 精確反查一張訂單（不限狀態）；抓到後再比對一次值，防止條件沒套上時抓錯單。 */
	public static function by_meta( string $meta_key, string $meta_value ): ?int {
		if ( '' === $meta_value || '' === $meta_key ) {
			return null;
		}
		$found = self::orders(
			[
				'limit'         => 1,
				'return'        => 'ids',
				// 不限狀態：關掉自訂狀態區塊後，停在 moksa-* 的訂單不在預設範圍內，通知會找不到單。
				'status'        => 'all',
				self::QUERY_VAR => self::equals( $meta_key, $meta_value ),
			]
		);
		if ( empty( $found ) ) {
			return null;
		}
		$order = wc_get_order( (int) $found[0] );
		if ( ! $order instanceof \WC_Abstract_Order || (string) $order->get_meta( $meta_key ) !== $meta_value ) {
			return null;
		}
		return (int) $found[0];
	}

	/**
	 * 單一條件「meta 等於某值」。
	 *
	 * @return array<string,string>
	 */
	public static function equals( string $meta_key, string $meta_value ): array {
		return [
			'key'   => $meta_key,
			'value' => $meta_value,
		];
	}

	private function __construct() {}
}
