<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup\Index;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單查號加速索引表 —— 把訂單上的台灣號碼（發票 / 物流 / 金流…）反正規化到
 * 一張 indexed 表，查詢從「wc_orders_meta LIKE 全掃」變成單表 indexed lookup。
 *
 * 純加速層：索引關閉或尚未建好時，OrderNumberLookup 自動 fallback 回 wc_get_orders。
 */
final class Table {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'moksafowo_order_lookup_index_db_version';
	const ENABLED_OPTION    = 'moksafowo_order_lookup_index_enabled';

	public static function name(): string {
		global $wpdb;
		return $wpdb->prefix . 'moksafowo_order_lookup';
	}

	public static function is_enabled(): bool {
		return 'yes' === get_option( self::ENABLED_OPTION, 'no' );
	}

	/**
	 * 索引可用 = 已啟用 + 表存在。查詢前先問這個，否則 fallback。
	 */
	public static function is_ready(): bool {
		return self::is_enabled() && self::exists();
	}

	public static function exists(): bool {
		global $wpdb;
		$name = self::name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表存在性檢查（schema read，無快取意義）；查詢已 prepare。
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name;
	}

	/**
	 * 依 DB 版本建/升表（冪等，只在版本不符時跑 dbDelta）。
	 */
	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION && self::exists() ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$name    = self::name();
		$collate = $wpdb->get_charset_collate();

		// num 建索引供 exact / prefix 查詢；主鍵 (order_id, field, num) 兼去重 +
		// 以 order_id 為前綴利於依訂單刪除。num 用大小寫不敏感 collation。
		$sql = "CREATE TABLE {$name} (
			order_id BIGINT UNSIGNED NOT NULL,
			field VARCHAR(20) NOT NULL,
			num VARCHAR(64) NOT NULL,
			PRIMARY KEY  (order_id, field, num),
			KEY num (num)
		) {$collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function drop(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 自有表 DDL。
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::name() ) );
		delete_option( self::DB_VERSION_OPTION );
	}

	public static function count_rows(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表計數。
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::name() ) );
	}

	public static function count_orders(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表計數。
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT order_id) FROM %i', self::name() ) );
	}

	public static function truncate(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 自有表清空。
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::name() ) );
	}

	public static function delete_order( int $order_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表，依訂單清列。
		$wpdb->delete( self::name(), [ 'order_id' => $order_id ], [ '%d' ] );
	}

	/**
	 * 以「先刪後插」覆寫單一訂單的所有索引列。
	 *
	 * @param int                                          $order_id 訂單 ID。
	 * @param array<int, array{field:string, num:string}> $pairs    號碼類型 + 值。
	 */
	public static function replace_order( int $order_id, array $pairs ): void {
		global $wpdb;
		self::delete_order( $order_id );
		if ( empty( $pairs ) ) {
			return;
		}
		// 每張訂單只有個位數的號碼（發票 / 物流 / 金流），逐列走 $wpdb->insert() 即可，
		// 不需要自組 VALUES 清單 —— 也就沒有任何動態拼接的 SQL。
		foreach ( $pairs as $p ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表插入。
			$wpdb->insert(
				self::name(),
				[
					'order_id' => $order_id,
					'field'    => (string) $p['field'],
					'num'      => (string) $p['num'],
				],
				[ '%d', '%s', '%s' ]
			);
		}
	}

	/**
	 * 查號 —— 在指定號碼類型內找 exact 或 prefix 命中的訂單 ID（exact 優先）。
	 *
	 * @param string[] $fields 要搜尋的號碼類型（已 gate）。
	 * @param string   $term   搜尋字串。
	 * @param int      $limit  最多回傳幾筆。
	 * @return int[] 訂單 ID（exact 命中排前）。
	 */
	public static function search( array $fields, string $term, int $limit ): array {
		global $wpdb;
		$fields = array_values( array_filter( array_map( 'strval', $fields ) ) );
		if ( empty( $fields ) || '' === $term ) {
			return [];
		}
		// 號碼類型清單用 FIND_IN_SET 當單一 %s 參數傳，而不是拼 IN (%s, %s, ...) —
		// SQL 因此是完全靜態的字串，placeholder 數量固定。`field` 本來就不在索引裡
		// （KEY num (num) 才是驅動查詢的），兩種寫法它都只是 post-filter，沒有效能差異。
		$field_set = implode( ',', $fields );
		$like      = $wpdb->esc_like( $term ) . '%';
		// exact (num = term) 排在 prefix 命中之前。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表查詢。
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT order_id, MAX( num = %s ) AS exact_hit FROM %i
					WHERE FIND_IN_SET( field, %s ) AND ( num = %s OR num LIKE %s )
					GROUP BY order_id
					ORDER BY exact_hit DESC
					LIMIT %d',
				$term,
				self::name(),
				$field_set,
				$term,
				$like,
				max( 1, $limit )
			)
		);
		return array_map( 'intval', $rows );
	}
}
