<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Address;

defined( 'ABSPATH' ) || exit;

/**
 * 台灣手機號碼的正規化與檢查。
 *
 * 顧客實際會打出來的東西：09xx-xxx-xxx、09xx xxx xxx、(09xx)xxxxxx、+886912345678、
 * 886912345678、０９１２３４５６７８（全形）。這些都是同一支號碼，先洗成 09xxxxxxxx
 * 再判斷，比直接擋掉要求重打友善得多，訂單裡存的也統一。
 *
 * 只認手機（09 開頭共 10 碼）。市話沒有一致的碼長（02 是 8 碼、其餘區碼多為 7 碼，
 * 還有分機），硬塞進同一條規則只會誤擋，所以這個開關的定位就是「手機」。
 */
final class PhoneRule {

	private const OPTION = 'moksafowo_tw_address_phone_validate';

	public static function enabled(): bool {
		return \Moksafowo\Settings\AdvancedSections::is_on( \Moksafowo\Settings\AdvancedSections::TW_ADDRESS )
			&& 'yes' === get_option( self::OPTION, 'no' );
	}

	/**
	 * 洗成 09xxxxxxxx。洗不出合法格式時原樣回傳，交給 is_valid() 去擋 ——
	 * 這裡不負責判斷對錯，免得把錯的號碼硬掰成看起來對的。
	 */
	public static function normalize( string $raw ): string {
		$value = trim( $raw );
		if ( '' === $value ) {
			return '';
		}

		// 全形數字與全形加號 → 半形
		$value = strtr(
			$value,
			[
				'０' => '0',
				'１' => '1',
				'２' => '2',
				'３' => '3',
				'４' => '4',
				'５' => '5',
				'６' => '6',
				'７' => '7',
				'８' => '8',
				'９' => '9',
				'＋' => '+',
			]
		);

		$plus  = str_starts_with( $value, '+' );
		$value = preg_replace( '/\D+/', '', $value );
		if ( ! is_string( $value ) || '' === $value ) {
			return $raw;
		}

		// +886 / 886 國碼 → 補回開頭的 0
		if ( str_starts_with( $value, '886' ) && ( $plus || strlen( $value ) >= 12 ) ) {
			$value = '0' . substr( $value, 3 );
		}

		return $value;
	}

	public static function is_valid( string $normalized ): bool {
		return 1 === preg_match( '/^09\d{8}$/', $normalized );
	}

	/** 錯誤訊息。放這裡讓古典 / 區塊 / 我的帳號三條路徑講同一句話。 */
	public static function message(): string {
		return __( 'Enter a Taiwanese mobile number: 10 digits starting with 09 (for example 0912345678).', 'moksa-for-woocommerce' );
	}
}
