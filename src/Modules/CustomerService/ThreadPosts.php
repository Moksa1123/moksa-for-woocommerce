<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\CustomerService;

defined( 'ABSPATH' ) || exit;

/**
 * 客服對話 / 訊息：WordPress 資料型別實作（取代自有表）。
 *
 * - 一個對話 = 一篇私有 CPT moksafowo_cs_thread：post_parent = 訂單 ID、post_excerpt = customer_ref、
 *   post_status = moksafowo-open／-answered／-closed、menu_order = 店家未讀（0／1）、
 *   post_date／post_modified = 建立／更新時間。
 * - 一則訊息 = 一則留言：comment_type = moksafowo_cs、comment_author = 寄件方、
 *   comment_approved = moksafowo_cs（自訂值：不進留言列表、小工具、RSS，也不計入留言數）。
 *   用 wp_insert_comment()：不會觸發留言通知信、Akismet。
 *
 * 對外回傳的陣列形狀與 LegacyThreads 相同（id、order_id、customer_ref、status、unread_staff、
 * created_at、updated_at；訊息是 id、sender、body、created_at）。
 */
final class ThreadPosts {

	public const POST_TYPE      = 'moksafowo_cs_thread';
	public const COMMENT_TYPE   = 'moksafowo_cs';
	public const COMMENT_STATUS = 'moksafowo_cs';
	public const LEGACY_PREFIX  = 'cs-legacy-';

	/** 對外狀態 => post_status。 */
	private const STATUSES = [
		'open'     => 'moksafowo-open',
		'answered' => 'moksafowo-answered',
		'closed'   => 'moksafowo-closed',
	];

	/** 客服模組與 AI 助手（收件匣）都會呼叫；註冊只做一次。未註冊的自訂狀態 WP_Query 會直接丟掉。 */
	public static function boot(): void {
		if ( did_action( 'init' ) ) {
			self::register();
			return;
		}
		if ( false === has_action( 'init', [ self::class, 'register' ] ) ) {
			add_action( 'init', [ self::class, 'register' ] );
		}
	}

	public static function register(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
		register_post_type(
			self::POST_TYPE,
			[
				'label'               => 'Moksa customer service thread',
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				// 用 WC 訂單的權限：留言（訊息）的讀改刪會對應到「能不能編輯這張訂單」，Editor 等沒有訂單權限的角色
				// 碰不到顧客對話（WP 留言 REST、後台 comment.php 都走 edit_comment → edit_post 的對應）。
				'capability_type'     => [ 'shop_order', 'shop_orders' ],
				'map_meta_cap'        => true,
				'supports'            => [ 'title' ],
			]
		);
		foreach ( self::STATUSES as $status ) {
			register_post_status(
				$status,
				[
					'public'                    => false,
					'internal'                  => true,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => false,
					'show_in_admin_status_list' => false,
				]
			);
		}
	}

	public static function open_or_get( int $order_id, string $customer_ref ): int {
		$id = self::thread_id_for_order( $order_id );
		if ( $id > 0 ) {
			return $id;
		}
		return self::insert_thread( $order_id, $customer_ref, 'open', 0, current_time( 'mysql' ), '' );
	}

	/**
	 * 建一篇對話文章（搬遷時也用：帶入舊的時間與 post_name）。
	 */
	public static function insert_thread( int $order_id, string $customer_ref, string $status, int $unread, string $created, string $post_name ): int {
		$args = [
			'post_type'    => self::POST_TYPE,
			// 不掛在任何後台帳號名下（搬遷或背景工作的觸發者不該變成對話作者）。
			'post_author'  => 0,
			'post_status'  => self::STATUSES[ $status ] ?? self::STATUSES['open'],
			// 內部紀錄標題，不會顯示給顧客，所以不翻譯。
			'post_title'   => 'Order #' . $order_id,
			'post_excerpt' => $customer_ref,
			'post_parent'  => $order_id,
			'menu_order'   => $unread ? 1 : 0,
			'post_date'    => $created,
		];
		if ( '' !== $post_name ) {
			$args['post_name'] = $post_name;
		}
		$id = wp_insert_post( $args, true );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	public static function add_message( int $thread_id, string $sender, string $body ): int {
		$thread_id = self::resolve_id( $thread_id );
		if ( $thread_id <= 0 ) {
			return 0;
		}
		$mid = self::insert_message( $thread_id, $sender, $body, current_time( 'mysql' ) );
		// 最後一則是顧客寫的就待回覆；店員或 AI 回了就是已回覆（結案的對話顧客再寫也會重新打開）。
		$update = [
			'ID'          => $thread_id,
			'post_status' => 'customer' === $sender ? self::STATUSES['open'] : self::STATUSES['answered'],
		];
		if ( 'customer' === $sender ) {
			$update['menu_order'] = 1;
		}
		wp_update_post( $update );
		return $mid;
	}

	public static function insert_message( int $thread_id, string $sender, string $body, string $created ): int {
		$mid = wp_insert_comment(
			[
				'comment_post_ID'  => $thread_id,
				'comment_type'     => self::COMMENT_TYPE,
				'comment_approved' => self::COMMENT_STATUS,
				'comment_author'   => $sender,
				'comment_content'  => $body,
				'comment_date'     => $created,
				'comment_date_gmt' => get_gmt_from_date( $created ),
				'user_id'          => 0,
			]
		);
		return $mid ? (int) $mid : 0;
	}

	/**
	 * 搬遷用：設定狀態、未讀，並把「最後更新時間」設成指定時間（wp_update_post 預設會改成現在）。
	 *
	 * @param int         $thread_id 對話（本 CPT 的文章 ID）。
	 * @param string      $status    open／answered／closed。
	 * @param int|null    $unread    店家未讀 0／1；null 表示不動。
	 * @param string      $modified  最後更新時間（站台時區 Y-m-d H:i:s）。
	 */
	public static function sync_state( int $thread_id, string $status, ?int $unread, string $modified ): void {
		$thread_id = self::resolve_id( $thread_id );
		if ( $thread_id <= 0 ) {
			return;
		}
		$update = [ 'ID' => $thread_id ];
		if ( isset( self::STATUSES[ $status ] ) ) {
			$update['post_status'] = self::STATUSES[ $status ];
		}
		if ( null !== $unread ) {
			$update['menu_order'] = $unread ? 1 : 0;
		}
		$keep = static function ( array $data ) use ( $modified ): array {
			if ( '' !== $modified ) {
				$data['post_modified']     = $modified;
				$data['post_modified_gmt'] = get_gmt_from_date( $modified );
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $keep, 99 );
		wp_update_post( $update );
		remove_filter( 'wp_insert_post_data', $keep, 99 );
	}

	public static function set_status( int $thread_id, string $status ): void {
		$thread_id = self::resolve_id( $thread_id );
		if ( $thread_id > 0 && isset( self::STATUSES[ $status ] ) ) {
			wp_update_post(
				[
					'ID'          => $thread_id,
					'post_status' => self::STATUSES[ $status ],
				]
			);
		}
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_messages( int $thread_id ): array {
		$thread_id = self::resolve_id( $thread_id );
		$out       = [];
		foreach ( self::comments( $thread_id ) as $c ) {
			$out[] = [
				'id'         => (int) $c->comment_ID,
				'sender'     => (string) $c->comment_author,
				'body'       => (string) $c->comment_content,
				'created_at' => (string) $c->comment_date,
			];
		}
		return $out;
	}

	/** 這個對話目前有幾則訊息（搬遷補漏用）。 */
	public static function count_messages( int $thread_id ): int {
		return count( self::comments( self::resolve_id( $thread_id ) ) );
	}

	/**
	 * 把傳進來的 id 解析成本 CPT 的文章 ID（舊對話 id 也會對應過去）；不是對話就回 0。
	 * 每個寫入都要先過這裡：舊網址 ?thread=舊id 的數字可能剛好是一篇商品或頁面的 ID，
	 * 直接拿去 wp_update_post／加留言會改到不相干的文章。
	 */
	public static function resolve_id( int $id ): int {
		$row = self::get_thread( $id );
		return null === $row ? 0 : (int) $row['id'];
	}

	/**
	 * 依 ID 取對話；ID 不是本 CPT 的文章時，當成搬遷前的舊對話 id 再找一次（後台舊網址還能開）。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_thread( int $id ): ?array {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			$post = self::find_legacy( $id );
		}
		return $post ? self::to_row( $post ) : null;
	}

	/** 舊對話 id 對應到的文章（搬遷時寫入的 post_name = cs-legacy-{舊id}）。 */
	public static function find_legacy( int $legacy_id ): ?\WP_Post {
		if ( $legacy_id <= 0 ) {
			return null;
		}
		$posts = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => array_values( self::STATUSES ),
				'name'           => self::LEGACY_PREFIX . $legacy_id,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			]
		);
		return ( isset( $posts[0] ) && $posts[0] instanceof \WP_Post ) ? $posts[0] : null;
	}

	public static function thread_id_for_order( int $order_id ): int {
		if ( $order_id <= 0 ) {
			return 0;
		}
		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_parent'    => $order_id,
				'post_status'    => [ self::STATUSES['open'], self::STATUSES['answered'] ],
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		return isset( $ids[0] ) ? (int) $ids[0] : 0;
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public static function list_threads( int $limit = 50 ): array {
		$posts = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => array_values( self::STATUSES ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'posts_per_page' => max( 1, $limit ),
				'no_found_rows'  => true,
			]
		);
		return array_map( [ self::class, 'to_row' ], $posts );
	}

	public static function count_unread(): int {
		$q = new \WP_Query(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => array_values( self::STATUSES ),
				'menu_order'     => 1,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);
		return (int) $q->found_posts;
	}

	public static function mark_staff_read( int $id ): void {
		$row = self::get_thread( $id );
		if ( null !== $row ) {
			wp_update_post(
				[
					'ID'         => (int) $row['id'],
					'menu_order' => 0,
				]
			);
		}
	}

	/**
	 * @return \WP_Comment[]
	 */
	private static function comments( int $thread_id ): array {
		if ( $thread_id <= 0 ) {
			return [];
		}
		$list = get_comments(
			[
				'post_id' => $thread_id,
				'type'    => self::COMMENT_TYPE,
				'status'  => self::COMMENT_STATUS,
				'orderby' => 'comment_ID',
				'order'   => 'ASC',
			]
		);
		return is_array( $list ) ? $list : [];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function to_row( \WP_Post $post ): array {
		$status = (string) array_search( $post->post_status, self::STATUSES, true );
		return [
			'id'           => (int) $post->ID,
			'order_id'     => (int) $post->post_parent,
			'customer_ref' => (string) $post->post_excerpt,
			'status'       => '' !== $status ? $status : 'open',
			'unread_staff' => (int) $post->menu_order,
			'created_at'   => (string) $post->post_date,
			'updated_at'   => (string) $post->post_modified,
		];
	}

	private function __construct() {}
}
