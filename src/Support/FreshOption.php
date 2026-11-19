<?php

declare( strict_types=1 );

namespace Moksafowo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 讀一次性旗標時，不相信「這個選項不存在」的快取（SOP §3 G；寫法同 moksa-coupon Support\FreshOption c4c5915）。
 *
 * 有持久物件快取（Redis、Memcached）時，WordPress 共用一份 notoptions 清單。兩個同時跑的請求各自
 * 更新它會互相蓋掉，資料庫裡明明有的選項被記成不存在。管一次性工作的旗標（搬遷完成、儲存方式、
 * 資料表版本）讀成不存在，工作就會重跑：例如客服儲存方式被重算回舊表、搬遷游標歸零、
 * 店家後來關掉的設定被搬遷再打開。
 * 所以快取回「不存在」時，只把這一個名稱從快取清單拿掉，再問一次資料庫。
 *
 * 沒有持久快取時清單只活在這個請求、不會過期，直接用 get_option()。同名每個請求最多重查一次。
 */
final class FreshOption {

	/** @var array<string,true> 這個請求已經重查過的名稱。 */
	private static array $checked = array();

	/**
	 * @param string $name     選項名稱。
	 * @param mixed  $fallback 真的不存在時回傳。
	 * @return mixed
	 */
	public static function get( string $name, $fallback = false ) {
		$value = get_option( $name, null );
		if ( null !== $value ) {
			return $value;
		}
		if ( self::recheck( $name ) ) {
			$value = get_option( $name, null );
		}
		return null === $value ? $fallback : $value;
	}

	private static function recheck( string $name ): bool {
		if ( ! wp_using_ext_object_cache() || isset( self::$checked[ $name ] ) ) {
			return false;
		}
		self::$checked[ $name ] = true;
		$notoptions             = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		wp_cache_delete( $name, 'options' );
		return true;
	}

	private function __construct() {}
}
