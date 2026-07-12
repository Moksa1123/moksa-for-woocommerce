<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\CustomerService;

defined( 'ABSPATH' ) || exit;

/**
 * 客服留言自有表 —— threads(對話)+ messages(訊息)。
 * 不塞 order meta,HPOS 友善。顧客驗證後留言 → 店家後台 Inbox 回覆。
 */
final class Schema {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'moksafowo_customer_service_db_version';

	public static function threads_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'moksafowo_cs_threads';
	}

	public static function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'moksafowo_cs_messages';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION && self::exists() ) {
			return;
		}
		self::install();
	}

	public static function exists(): bool {
		global $wpdb;
		$t = self::threads_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表存在性檢查;查詢已 prepare。
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate  = $wpdb->get_charset_collate();
		$threads  = self::threads_table();
		$messages = self::messages_table();

		dbDelta(
			"CREATE TABLE {$threads} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				customer_ref VARCHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'open',
				unread_staff TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY status (status)
			) {$collate};"
		);
		dbDelta(
			"CREATE TABLE {$messages} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				thread_id BIGINT UNSIGNED NOT NULL,
				sender VARCHAR(10) NOT NULL DEFAULT 'customer',
				body TEXT NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY thread_id (thread_id)
			) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function drop(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表 DDL。
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::messages_table() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表 DDL。
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::threads_table() ) );
		delete_option( self::DB_VERSION_OPTION );
	}
}
