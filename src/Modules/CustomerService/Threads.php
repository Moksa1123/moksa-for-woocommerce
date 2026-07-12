<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\CustomerService;

defined( 'ABSPATH' ) || exit;

/**
 * 客服對話 / 訊息存取層(自有表)。
 */
final class Threads {

	/**
	 * 取該訂單目前 open 的對話,沒有就開一個。回傳 thread id。
	 */
	public static function open_or_get( int $order_id, string $customer_ref ): int {
		global $wpdb;
		$t = Schema::threads_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表查詢。
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE order_id = %d AND status = 'open' ORDER BY id DESC LIMIT 1", $t, $order_id ) );
		if ( $id > 0 ) {
			return $id;
		}
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表插入。
		$wpdb->insert(
			$t,
			array(
				'order_id'     => $order_id,
				'customer_ref' => $customer_ref,
				'status'       => 'open',
				'unread_staff' => 0,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function add_message( int $thread_id, string $sender, string $body ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表插入。
		$wpdb->insert(
			Schema::messages_table(),
			array(
				'thread_id'  => $thread_id,
				'sender'     => $sender,
				'body'       => $body,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);
		$mid = (int) $wpdb->insert_id;

		$data = array( 'updated_at' => $now );
		if ( 'customer' === $sender ) {
			$data['unread_staff'] = 1;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表更新。
		$wpdb->update( Schema::threads_table(), $data, array( 'id' => $thread_id ) );
		return $mid;
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_messages( int $thread_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表查詢。
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, sender, body, created_at FROM %i WHERE thread_id = %d ORDER BY id ASC', Schema::messages_table(), $thread_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get_thread( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表查詢。
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::threads_table(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * 取訂單對應的 open thread id(顧客端 poll 用,不新建)。
	 */
	public static function thread_id_for_order( int $order_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表查詢。
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE order_id = %d AND status = 'open' ORDER BY id DESC LIMIT 1", Schema::threads_table(), $order_id ) );
	}

	/**
	 * 後台:近期對話清單。
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function list_threads( int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表查詢。
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY updated_at DESC LIMIT %d', Schema::threads_table(), max( 1, $limit ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function count_unread(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表查詢。
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE unread_staff = 1', Schema::threads_table() ) );
	}

	public static function mark_staff_read( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自有表更新。
		$wpdb->update( Schema::threads_table(), array( 'unread_staff' => 0 ), array( 'id' => $id ) );
	}
}
