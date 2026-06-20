<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Admin;

defined( 'ABSPATH' ) || exit;

final class BatchPrintRegistry {

	private static ?array $cache = null;

	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$providers  = (array) apply_filters( 'moksafowo_shipping_batch_print_providers', [] );
		$normalized = [];
		foreach ( $providers as $key => $entry ) {
			$key   = (string) $key;
			$entry = (array) $entry;
			if ( '' === $key ) {
				continue;
			}
			if ( empty( $entry['method_ids'] ) || ! is_callable( $entry['handler'] ?? null ) ) {
				continue;
			}
			// method_ids: list<string> → title fallback 為 method_id；array<string,string> → 顯示中文
			$raw_methods   = (array) $entry['method_ids'];
			$method_titles = [];
			foreach ( $raw_methods as $k => $v ) {
				if ( is_int( $k ) ) {
					$method_titles[ (string) $v ] = (string) $v;  // legacy list, fallback raw
				} else {
					$method_titles[ (string) $k ] = (string) $v;
				}
			}
			$normalized[ $key ] = [
				'key'             => $key,
				'label'           => (string) ( $entry['label'] ?? $key ),
				'category'        => (string) ( $entry['category'] ?? 'cvs' ),
				'method_ids'      => array_keys( $method_titles ),
				'method_titles'   => $method_titles,
				'handler'         => $entry['handler'],
				'record_counter'  => is_callable( $entry['record_counter'] ?? null ) ? $entry['record_counter'] : null,
				// '1'=A4, '2'=A6；provider 可限定（PAYUNi 只 ['1']）
				'paper_modes'     => array_values( array_intersect( [ '1', '2' ], (array) ( $entry['paper_modes'] ?? [ '1', '2' ] ) ) ),
				// fn( WC_Order ): string[] — 行級紙張（ECPay: FAMI/OK 只 A4，UNIMART 可 A4+A6）
				'row_paper_modes' => is_callable( $entry['row_paper_modes'] ?? null ) ? $entry['row_paper_modes'] : null,
				// fn( WC_Order ): int[] — 多溫層溫層集合（溫層 pill 用）
				'record_temps'    => is_callable( $entry['record_temps'] ?? null ) ? $entry['record_temps'] : null,
			];
		}
		self::$cache = $normalized;
		return self::$cache;
	}

	public static function get( string $key ): ?array {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function reset_cache(): void {
		self::$cache = null;
	}
}
