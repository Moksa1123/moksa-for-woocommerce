<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup\Index;

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
		$name = self::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 自有表 DDL，表名由 prefix 組成。
		$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
		delete_option( self::DB_VERSION_OPTION );
	}

	public static function count_rows(): int {
		global $wpdb;
		$name = self::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 自有表計數,表名由 prefix 組成。
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$name}" );
	}

	public static function count_orders(): int {
		global $wpdb;
		$name = self::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 自有表計數,表名由 prefix 組成。
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT order_id) FROM {$name}" );
	}

	public static function truncate(): void {
		global $wpdb;
		$name = self::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 自有表清空，表名由 prefix 組成。
		$wpdb->query( "TRUNCATE TABLE {$name}" );
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
		$name   = self::name();
		$values = [];
		$args   = [];
		foreach ( $pairs as $p ) {
			$values[] = '(%d, %s, %s)';
			$args[]   = $order_id;
			$args[]   = (string) $p['field'];
			$args[]   = (string) $p['num'];
		}
		$placeholders = implode( ',', $values );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 自有表批次插入；表名由 prefix 組成、placeholder 在 $placeholders 變數內、值經 $wpdb->prepare 參數化。
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$name} (order_id, field, num) VALUES {$placeholders}", $args ) );
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
		$name     = self::name();
		$field_ph = implode( ',', array_fill( 0, count( $fields ), '%s' ) );
		$like     = $wpdb->esc_like( $term ) . '%';
		// exact (num = term) 排在 prefix 命中之前。
		$sql = "SELECT order_id, MAX( num = %s ) AS exact_hit
			FROM {$name}
			WHERE field IN ({$field_ph}) AND ( num = %s OR num LIKE %s )
			GROUP BY order_id
			ORDER BY exact_hit DESC
			LIMIT %d";
		// 參數順序：field IN(...) 先，接 (exact 比對的 term) + (num=term) + (LIKE) + limit。
		$prepared_args = array_merge( [ $term ], $fields, [ $term, $like, max( 1, $limit ) ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 自有表查詢；$sql 模板為字面，僅內插表名與 placeholder 數（非使用者輸入），值經 prepare 參數化。
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $prepared_args ) );
		return array_map( 'intval', $rows );
	}
}
