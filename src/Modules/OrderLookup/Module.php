<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

use MoksaWeb\Mowc\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單查號搜尋 — 讓 WC 訂單搜尋框認得台灣特有的發票號 / 物流單號 / 金流交易序號。
 *
 * 只掛兩個 WC 原生 filter，把「已啟用模組」的號碼 meta key 加進搜尋；
 * 不自行下 query、不碰其他模組內部，完全解耦。
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'order_lookup';
	}

	public function label(): string {
		return __( '訂單查號搜尋 — 發票號 / 物流單號 / 金流交易序號', 'mo-ectools' );
	}

	public function category(): string {
		return 'tools';
	}

	public function name(): string {
		return __( '訂單查號搜尋', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '在訂單搜尋框直接用發票號 / 物流單號 / 金流交易序號找訂單', 'mo-ectools' );
	}

	public function boot(): void {
		// HPOS（自訂訂單表）：WC 7.0+ 用此 filter 決定訂單搜尋要納入的 meta key。
		add_filter( 'woocommerce_order_table_search_query_meta_keys', [ SearchableKeys::class, 'add' ] );
		// CPT / legacy（compat sync 站）：對應的舊 filter。
		add_filter( 'woocommerce_shop_order_search_fields', [ SearchableKeys::class, 'add' ] );
	}
}
