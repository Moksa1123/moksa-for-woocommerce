<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup\Index;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單查號索引：WordPress 私有 taxonomy 實作（取代自有表 moksafowo_order_lookup）。
 *
 * 一個號碼（發票號碼／物流單號／金流交易編號）= 一個 term，名稱 `{field}:{num}`，
 * 用 term relationships 掛到訂單 ID 上（HPOS 的訂單 ID 也取自 posts 表，不會撞號）。
 * - 精確：get_term_by( 'name' ) 走 terms.name 索引。
 * - 前綴：get_terms( name__like ) 只能做雙邊 LIKE（掃過這個 taxonomy 的 term）；因為名稱都以
 *   `{field}:` 開頭、三個 field 互不為後綴，`%invoice:AB%` 實際上就是前綴比對，結果再檢查一次開頭。
 *
 * 儲存模式 option（STORAGE_OPTION）：legacy（還在用舊表）／terms。建完才切換，舊表不清空，可回滾。
 */
final class TermIndex {

	public const TAXONOMY       = 'moksafowo_order_ref';
	public const STORAGE_OPTION = 'moksafowo_order_lookup_storage';
	public const LEGACY         = 'legacy';
	public const TERMS          = 'terms';

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
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}
		register_taxonomy(
			self::TAXONOMY,
			'shop_order',
			[
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => false,
				'show_in_menu'          => false,
				'show_in_nav_menus'     => false,
				'show_in_rest'          => false,
				'show_tagcloud'         => false,
				'show_admin_column'     => false,
				'hierarchical'          => false,
				'rewrite'               => false,
				'query_var'             => false,
				// 物件是訂單 ID，不一定是文章（HPOS）：照關聯數計數，不看文章狀態。
				'update_count_callback' => '_update_generic_term_count',
				// 號碼本身是交易資料：XML-RPC wp.getTerms 等介面要的權限改成店長層級（預設是投稿者就能列）。
				'capabilities'          => [
					'manage_terms' => 'manage_woocommerce',
					'edit_terms'   => 'manage_woocommerce',
					'delete_terms' => 'manage_woocommerce',
					'assign_terms' => 'manage_woocommerce',
				],
			]
		);
	}

	public static function storage(): string {
		$mode = (string) get_option( self::STORAGE_OPTION, '' );
		if ( self::LEGACY === $mode || self::TERMS === $mode ) {
			return $mode;
		}
		// 第一次判斷：舊表存在就先沿用（等重建完才切換），否則直接用 taxonomy。
		$mode = Table::exists() ? self::LEGACY : self::TERMS;
		update_option( self::STORAGE_OPTION, $mode, false );
		return $mode;
	}

	public static function uses_terms(): bool {
		return self::TERMS === self::storage();
	}

	/**
	 * 覆寫一張訂單的所有號碼；號碼換掉後沒有訂單在用的舊 term 一併刪除。
	 *
	 * @param int                                          $order_id 訂單 ID。
	 * @param array<int, array{field:string, num:string}> $pairs    號碼類型 + 值。
	 */
	public static function replace_order( int $order_id, array $pairs ): void {
		if ( $order_id <= 0 ) {
			return;
		}
		self::register();
		$names = [];
		foreach ( $pairs as $p ) {
			$name = self::term_name( (string) $p['field'], (string) $p['num'] );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		$before = wp_get_object_terms( $order_id, self::TAXONOMY, [ 'fields' => 'ids' ] );
		$ids    = [];
		foreach ( array_unique( $names ) as $name ) {
			$tid = self::term_id_for( $name );
			if ( $tid > 0 ) {
				$ids[] = $tid;
			}
		}
		wp_set_object_terms( $order_id, $ids, self::TAXONOMY, false );
		self::delete_orphans( is_array( $before ) ? array_map( 'intval', $before ) : [] );
	}

	public static function delete_order( int $order_id ): void {
		if ( $order_id <= 0 ) {
			return;
		}
		self::register();
		$before = wp_get_object_terms( $order_id, self::TAXONOMY, [ 'fields' => 'ids' ] );
		wp_delete_object_term_relationships( $order_id, self::TAXONOMY );
		self::delete_orphans( is_array( $before ) ? array_map( 'intval', $before ) : [] );
	}

	/**
	 * 查號：先精確，精確有命中就只回精確的；沒有才做前綴。
	 *
	 * 實測（30 萬個號碼、MariaDB 10.6、冷查詢）：精確走 terms.name 索引約 1～2ms；
	 * 前綴是雙邊 LIKE，每掃一次約 160～200ms，所以所有欄位合成一次掃描，而且只在精確沒中時才掃。
	 *
	 * @param string[] $fields 要搜尋的號碼類型。
	 * @param string   $term   搜尋字串。
	 * @param int      $limit  最多回傳幾筆。
	 * @return int[]
	 */
	public static function search( array $fields, string $term, int $limit ): array {
		$term   = trim( $term );
		$fields = array_values( array_filter( array_map( 'strval', $fields ) ) );
		if ( '' === $term || empty( $fields ) ) {
			return [];
		}
		self::register();
		$limit = max( 1, $limit );

		$names = array_values( array_filter( array_map( static fn( string $f ): string => self::term_name( $f, $term ), $fields ) ) );
		$exact = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'slug'       => array_map( [ self::class, 'slug' ], $names ),
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		$exact = is_array( $exact ) ? array_map( 'intval', $exact ) : [];
		if ( [] !== $exact ) {
			return self::orders_of( $exact, $limit );
		}

		// 前綴：一次掃描涵蓋所有欄位（比對 `:term`），再只留指定欄位、而且號碼以 term 開頭的。
		$like = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'name__like' => ':' . $term,
				'hide_empty' => false,
				'number'     => $limit * 3,
				'fields'     => 'id=>name',
			]
		);
		$pref = [];
		foreach ( is_array( $like ) ? $like : [] as $tid => $tname ) {
			$parts = explode( ':', (string) $tname, 2 );
			if ( 2 === count( $parts ) && in_array( $parts[0], $fields, true ) && 0 === stripos( $parts[1], $term ) ) {
				$pref[] = (int) $tid;
			}
		}
		return [] === $pref ? [] : self::orders_of( $pref, $limit );
	}

	/**
	 * @param int[] $term_ids 命中的 term。
	 * @return int[] 訂單 ID（最多 $limit 筆）。
	 */
	private static function orders_of( array $term_ids, int $limit ): array {
		$objects = get_objects_in_term( $term_ids, self::TAXONOMY );
		$ids     = [];
		foreach ( is_array( $objects ) ? $objects : [] as $oid ) {
			$ids[ (int) $oid ] = true;
			if ( count( $ids ) >= $limit ) {
				break;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * term 的 slug：小寫後的完整名稱取 md5。
	 *
	 * 不讓 WP 用 sanitize_title 自動產生——那會把大小寫以外、標點不同的號碼（ABC-1 與 ABC 1）併成同一個 term，
	 * 查單就會查到不相干的訂單。小寫是刻意的：跟舊索引表一樣大小寫不分。
	 */
	public static function slug( string $name ): string {
		return 'r' . md5( mb_strtolower( $name ) );
	}

	/** 找到或建立這個號碼的 term（以自訂 slug 認定是否同一個）。 */
	private static function term_id_for( string $name ): int {
		$slug = self::slug( $name );
		$term = get_term_by( 'slug', $slug, self::TAXONOMY );
		if ( $term instanceof \WP_Term ) {
			return (int) $term->term_id;
		}
		$made = wp_insert_term( $name, self::TAXONOMY, [ 'slug' => $slug ] );
		if ( is_wp_error( $made ) ) {
			// 同時有別的請求建了同一個 term。
			$term = get_term_by( 'slug', $slug, self::TAXONOMY );
			return $term instanceof \WP_Term ? (int) $term->term_id : 0;
		}
		return (int) $made['term_id'];
	}

	/** term 名稱：`{field}:{num}`；號碼去頭尾空白，超過 terms.name 長度的截掉。 */
	public static function term_name( string $field, string $num ): string {
		$num = trim( $num );
		if ( '' === $field || '' === $num ) {
			return '';
		}
		return mb_substr( $field . ':' . $num, 0, 190 );
	}

	/** @param int[] $term_ids 剛才掛在訂單上的 term。 */
	private static function delete_orphans( array $term_ids ): void {
		foreach ( $term_ids as $tid ) {
			$t = get_term( $tid, self::TAXONOMY );
			if ( $t instanceof \WP_Term && 0 === (int) $t->count ) {
				wp_delete_term( $tid, self::TAXONOMY );
			}
		}
	}

	private function __construct() {}
}
