<?php

declare( strict_types=1 );

namespace Moksafowo\Order;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單備註格式：第一行一句話說發生什麼事，下面列出查問題用得到的欄位，沒有值的不印。
 *
 * 除錯 log 預設沒開，備註常是商家唯一看得到的線索，所以欄位要留，只是要好讀；
 * 不要把 API 回傳整包倒進來。
 */
final class Note {

	/**
	 * @param string               $headline 一句話的事件描述。
	 * @param array<string, mixed> $details  標籤 => 值（已翻譯的標籤）。
	 */
	public static function build( string $headline, array $details = [] ): string {
		$out = esc_html( $headline );
		foreach ( $details as $label => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			/* translators: 1: field label, 2: value */
			$out .= '<br>' . esc_html( sprintf( __( '%1$s: %2$s', 'moksa-for-woocommerce' ), $label, $value ) );
		}
		return $out;
	}

	private function __construct() {}
}
