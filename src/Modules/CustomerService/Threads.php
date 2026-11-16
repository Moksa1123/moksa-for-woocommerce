<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\CustomerService;

defined( 'ABSPATH' ) || exit;

/**
 * 客服對話 / 訊息存取層。依儲存模式（Storage）轉給 CPT 實作或舊自有表實作，
 * 呼叫端不用管資料存在哪裡；回傳的陣列形狀兩邊相同。
 */
final class Threads {

	public static function open_or_get( int $order_id, string $customer_ref ): int {
		return Storage::uses_cpt() ? ThreadPosts::open_or_get( $order_id, $customer_ref ) : LegacyThreads::open_or_get( $order_id, $customer_ref );
	}

	public static function add_message( int $thread_id, string $sender, string $body ): int {
		return Storage::uses_cpt() ? ThreadPosts::add_message( $thread_id, $sender, $body ) : LegacyThreads::add_message( $thread_id, $sender, $body );
	}

	public static function set_status( int $thread_id, string $status ): void {
		if ( Storage::uses_cpt() ) {
			$row = ThreadPosts::get_thread( $thread_id );
			if ( null !== $row ) {
				ThreadPosts::set_status( (int) $row['id'], $status );
			}
			return;
		}
		LegacyThreads::set_status( $thread_id, $status );
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_messages( int $thread_id ): array {
		if ( Storage::uses_cpt() ) {
			$row = ThreadPosts::get_thread( $thread_id );
			return null !== $row ? ThreadPosts::get_messages( (int) $row['id'] ) : array();
		}
		return LegacyThreads::get_messages( $thread_id );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get_thread( int $id ): ?array {
		return Storage::uses_cpt() ? ThreadPosts::get_thread( $id ) : LegacyThreads::get_thread( $id );
	}

	public static function thread_id_for_order( int $order_id ): int {
		return Storage::uses_cpt() ? ThreadPosts::thread_id_for_order( $order_id ) : LegacyThreads::thread_id_for_order( $order_id );
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public static function list_threads( int $limit = 50 ): array {
		return Storage::uses_cpt() ? ThreadPosts::list_threads( $limit ) : LegacyThreads::list_threads( $limit );
	}

	public static function count_unread(): int {
		return Storage::uses_cpt() ? ThreadPosts::count_unread() : LegacyThreads::count_unread();
	}

	public static function mark_staff_read( int $id ): void {
		if ( Storage::uses_cpt() ) {
			ThreadPosts::mark_staff_read( $id );
			return;
		}
		LegacyThreads::mark_staff_read( $id );
	}

	private function __construct() {}
}
