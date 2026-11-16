<?php
declare( strict_types=1 );

namespace Moksafowo\Tests\Unit;

use Moksafowo\Order\Lookup;
use PHPUnit\Framework\TestCase;

/**
 * Order\Lookup::to_meta_query() — 自訂查詢參數要轉成 meta_query，而且不能殘留在查詢參數裡。
 * 轉換漏掉的話 WC 會忽略不認得的參數、等於沒條件，金流通知就會對到錯的訂單。
 */
final class OrderLookupTest extends TestCase {

	public function test_single_clause_becomes_meta_query(): void {
		$out = Lookup::to_meta_query(
			[
				'limit'           => 1,
				Lookup::QUERY_VAR => Lookup::equals( '_moksafowo_x', 'ABC' ),
			]
		);
		self::assertArrayNotHasKey( Lookup::QUERY_VAR, $out );
		self::assertSame(
			[
				[
					'key'   => '_moksafowo_x',
					'value' => 'ABC',
				],
			],
			$out['meta_query']
		);
		self::assertSame( 1, $out['limit'] );
	}

	public function test_appends_to_existing_meta_query_as_one_group(): void {
		$group = [
			'relation' => 'OR',
			[
				'key'     => 'a',
				'compare' => 'EXISTS',
			],
			[
				'key'     => 'b',
				'compare' => 'EXISTS',
			],
		];
		// 先轉一次產生既有的 meta_query，再帶第二組條件轉一次。
		$first                       = Lookup::to_meta_query( [ Lookup::QUERY_VAR => [ 'key' => 'z' ] ] );
		$first[ Lookup::QUERY_VAR ] = $group;
		$out                         = Lookup::to_meta_query( $first );
		self::assertCount( 2, $out['meta_query'] );
		self::assertSame( $group, $out['meta_query'][1] );
	}

	public function test_without_query_var_is_untouched(): void {
		$args = [ 'limit' => 5 ];
		self::assertSame( $args, Lookup::to_meta_query( $args ) );
	}

	public function test_cpt_store_gets_clause_from_query_vars(): void {
		// 傳統儲存：get_wp_query_args() 已把不認得的參數原樣複製進 WP_Query 參數，條件要從 query_vars 取。
		$wp_args = [
			'post_type'       => 'shop_order',
			Lookup::QUERY_VAR => 'leftover',
		];
		$vars    = [ Lookup::QUERY_VAR => Lookup::equals( '_moksafowo_x', 'ABC' ) ];
		$out     = Lookup::translate_cpt( $wp_args, $vars );
		self::assertArrayNotHasKey( Lookup::QUERY_VAR, $out );
		self::assertSame( 'shop_order', $out['post_type'] );
		self::assertSame(
			[
				[
					'key'   => '_moksafowo_x',
					'value' => 'ABC',
				],
			],
			$out['meta_query']
		);
	}

	public function test_cpt_store_without_query_var_is_untouched(): void {
		$wp_args = [ 'post_type' => 'shop_order' ];
		self::assertSame( $wp_args, Lookup::translate_cpt( $wp_args, [ 'limit' => 1 ] ) );
	}

	public function test_translate_leaves_args_when_not_hpos(): void {
		// 單元測試沒有載入 WC，hpos() 為 false：參數必須原樣留給 translate_cpt()。
		$args = [ Lookup::QUERY_VAR => Lookup::equals( 'k', 'v' ) ];
		self::assertSame( $args, Lookup::translate( $args ) );
	}

	public function test_empty_clause_adds_nothing(): void {
		$out = Lookup::to_meta_query( [ Lookup::QUERY_VAR => [] ] );
		self::assertArrayNotHasKey( 'meta_query', $out );
		self::assertArrayNotHasKey( Lookup::QUERY_VAR, $out );
	}
}
