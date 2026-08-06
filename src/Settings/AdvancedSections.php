<?php

declare( strict_types=1 );

namespace Moksafowo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * 進階設定的區塊總開關。
 *
 * 每一區給一個「關掉整區」的開關，關掉就不註冊該區的 hook —— 不是把 UI 藏起來。
 * 商家跟別的外掛衝突時（最常見是台灣地址欄位）需要能一鍵關掉一整組行為，
 * 而不是逐項取消勾選還不確定關乾淨了沒。
 *
 * 預設一律 yes：既有站台升級後行為完全不變。
 *
 * ⚠️ 兩層開關的雷（見 CLAUDE.md §11 #14）：區塊開關關掉時，該區的子項一律視為關閉。
 * 子項自己的 get_option 不會知道這件事，所以**子項的判斷一定要先問過 is_on()**，
 * 否則會出現「子項打開了卻沒作用」，使用者只能猜。
 */
final class AdvancedSections {

	public const SHIPPING_COMMON = 'shipping_common';
	public const ORDER_STATUSES  = 'order_statuses';
	public const STATUS_COLORS   = 'status_colors';
	public const TW_ADDRESS      = 'tw_address';
	public const TW_FIELD_LAYOUT = 'tw_field_layout';
	public const ORDER_LOOKUP    = 'order_lookup';

	/**
	 * 訂單查號沿用既有的 moksafowo_order_lookup_enabled（它同時是模組開關），
	 * 不另外開一個 —— 兩個開關管同一件事正是 #14 那個雷。
	 */
	private const CUSTOM_OPTIONS = [
		self::ORDER_LOOKUP => 'moksafowo_order_lookup_enabled',
	];

	/** 預設值：查號沿用模組既有預設（no），其餘維持現況（yes）。 */
	private const DEFAULTS = [
		self::ORDER_LOOKUP => 'no',
	];

	public static function option( string $section ): string {
		return self::CUSTOM_OPTIONS[ $section ] ?? 'moksafowo_advanced_' . $section . '_enabled';
	}

	public static function is_on( string $section ): bool {
		$default = self::DEFAULTS[ $section ] ?? 'yes';
		return 'yes' === get_option( self::option( $section ), $default );
	}

	/**
	 * 區塊開關的設定欄位（放在每一區的第一列）。
	 *
	 * @param string $section 區塊常數。
	 * @param string $desc    關掉之後會怎樣，講具體一點。
	 * @return array<string,mixed>
	 */
	public static function toggle_field( string $section, string $desc ): array {
		return [
			'title'   => __( 'Enable this section', 'moksa-for-woocommerce' ),
			'id'      => self::option( $section ),
			'type'    => 'checkbox',
			'default' => self::DEFAULTS[ $section ] ?? 'yes',
			'desc'    => $desc,
			// JS 靠這個 class 把同一區其餘設定變灰（真正的停用在伺服器端）。
			// 不要加 'checkboxgroup' —— WC 只在該值是 start / end 或「沒設定」時才開關 <tr>，
			// 給空字串會讓 checkbox 被丟在 <table> 外面，整列消失。
			'class'   => 'moksafowo-section-master',
		];
	}
}
