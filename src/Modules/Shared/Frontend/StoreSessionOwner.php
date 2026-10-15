<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * 選店結果綁定「開地圖的那個瀏覽器」。
 *
 * 選店回呼是跨站導回，回呼當下不一定帶得到 session cookie，所以各家都先發一個 token、
 * 等顧客回到商店頁面時再兌換。token 若不綁定開地圖的人，攻擊者自己選完店後把帶 token 的
 * 連結傳給別人，就能改掉對方 session 裡的取貨門市。開地圖時記下 owner，兌換時比對。
 */
final class StoreSessionOwner {

	/**
	 * 目前請求的 WC session 識別。訪客也有（session cookie 不存在時先建立）。
	 */
	public static function current(): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}
		if ( ! WC()->session ) {
			WC()->initialize_session();
		}
		if ( ! WC()->session ) {
			return '';
		}
		if ( ! headers_sent() && method_exists( WC()->session, 'has_session' ) && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		return (string) WC()->session->get_customer_id();
	}

	/**
	 * 給「會原樣帶回」的地圖參數用（例如速買配 tempvar）：用 session 算出的 HMAC，每個瀏覽器不同。
	 */
	public static function tag( string $scope ): string {
		return substr( hash_hmac( 'sha256', $scope . '|' . self::current(), wp_salt( 'nonce' ) ), 0, 20 );
	}

	public static function matches( $owner ): bool {
		$owner = (string) $owner;
		return '' !== $owner && hash_equals( $owner, self::current() );
	}

	private function __construct() {}
}
