<?php

declare( strict_types=1 );

namespace Moksafowo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * 進階設定裡「我的帳號」選單的調整。只有勾選時才掛 filter。
 */
final class MyAccountMenu {

	public const HIDE_DOWNLOADS = 'moksafowo_myaccount_hide_downloads';

	public static function init(): void {
		if ( 'yes' !== get_option( self::HIDE_DOWNLOADS, 'no' ) ) {
			return;
		}
		add_filter( 'woocommerce_account_menu_items', [ __CLASS__, 'remove_downloads' ], 20 );
	}

	/**
	 * @param array<string,string> $items 選單項目。
	 * @return array<string,string>
	 */
	public static function remove_downloads( array $items ): array {
		unset( $items['downloads'] );
		return $items;
	}
}
