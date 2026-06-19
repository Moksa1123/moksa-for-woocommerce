<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shared\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * 發票「開立方式連動」單一真相 — 算出某 provider 目前開放哪些發票類型 / 載具。
 *
 * 同一份邏輯被三邊讀：後台 metabox 手動開立（AdminIssueForm）、區塊結帳、傳統結帳。
 * 商家在設定頁逐項開關（mo_<p>_channel_*、allow_donate、allow_b2b），三邊一起連動。
 * $option_prefix 例 'moksafowo_ecpay_invoice'。
 */
final class InvoiceChannels {

	/** 各 provider 硬體能力 — 哪些載具「做得到」（PayNow 無消費者平台，沒有會員載具）。 */
	private const CARRIER_CAPABILITY = [
		'moksafowo_ecpay_invoice'    => [ 'member', 'mobile', 'cert', 'paper' ],
		'moksafowo_ezpay_invoice'    => [ 'member', 'mobile', 'cert', 'paper' ],
		'moksafowo_smilepay_invoice' => [ 'member', 'mobile', 'cert', 'paper' ],
		'moksafowo_paynow_invoice'   => [ 'mobile', 'cert', 'paper' ],
		'moksafowo_amego_invoice'    => [ 'member', 'mobile', 'cert', 'paper' ],
	];

	/** 該 provider 能力上支援的載具 key（未知 prefix → 全部四種）。 */
	public static function supported_carriers( string $option_prefix ): array {
		return self::CARRIER_CAPABILITY[ $option_prefix ] ?? [ 'member', 'mobile', 'cert', 'paper' ];
	}

	/**
	 * 實際開放的載具 key（能力 ∩ 逐項開關），保底至少留一個避免空 select 擋死結帳。
	 * 開關 option：mo_<p>_channel_member / _mobile / _cert / _paper，預設 yes（升級沿用舊行為）。
	 */
	public static function enabled_carriers( string $option_prefix ): array {
		$caps = self::supported_carriers( $option_prefix );
		$out  = [];
		foreach ( $caps as $c ) {
			if ( 'yes' === get_option( "{$option_prefix}_channel_{$c}", 'yes' ) ) {
				$out[] = $c;
			}
		}
		if ( ! $out ) {
			// 全關 → 保底：能發紙本就紙本，否則第一個支援的
			$out = in_array( 'paper', $caps, true ) ? [ 'paper' ] : [ $caps[0] ];
		}
		return $out;
	}

	public static function allow_b2b( string $option_prefix ): bool {
		return 'yes' === get_option( "{$option_prefix}_allow_b2b", 'yes' );
	}

	public static function allow_donate( string $option_prefix ): bool {
		return 'yes' === get_option( "{$option_prefix}_allow_donate", 'yes' );
	}

	/** 啟用的發票類型 key — b2c_carrier 一定有（有載具保底），b2b / b2c_donate 看開關。 */
	public static function enabled_types( string $option_prefix ): array {
		$types = [ 'b2c_carrier' ];
		if ( self::allow_b2b( $option_prefix ) ) {
			$types[] = 'b2b';
		}
		if ( self::allow_donate( $option_prefix ) ) {
			$types[] = 'b2c_donate';
		}
		return $types;
	}

	/** 個人預設載具 — 限縮到有開放的；設定未填 / 已停用 → 退回第一個開放的。 */
	public static function default_carrier( string $option_prefix ): string {
		$enabled = self::enabled_carriers( $option_prefix );
		$def     = (string) get_option( "{$option_prefix}_default_carrier", '' );
		return ( '' !== $def && in_array( $def, $enabled, true ) ) ? $def : $enabled[0];
	}

	/**
	 * 捐贈單位清單 — 解析「捐贈單位」設定（每行 社福團體名稱|愛心碼），過濾格式不符 / 愛心碼非數字的行。
	 * 回傳 [ ['code'=>'25885','name'=>'伊甸社會福利基金會'], ... ]。
	 */
	public static function donate_orgs( string $option_prefix ): array {
		$raw = (string) get_option( "{$option_prefix}_donate_orgs", '' );
		if ( '' === trim( $raw ) ) {
			return [];
		}
		$orgs = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || ! str_contains( $line, '|' ) ) {
				continue;
			}
			[ $name, $code ] = array_map( 'trim', explode( '|', $line, 2 ) );
			$code            = (string) preg_replace( '/[^0-9]/', '', $code );
			if ( '' === $code || '' === $name ) {
				continue;
			}
			$orgs[] = [
				'code' => $code,
				'name' => $name,
			];
		}
		return $orgs;
	}

	public static function has_donate_orgs( string $option_prefix ): bool {
		return [] !== self::donate_orgs( $option_prefix );
	}

	/** 「捐贈單位」下拉（傳統結帳 / metabox）的 options（value=愛心碼、label=單位名稱，前置 placeholder）。 */
	public static function donate_select_options( string $option_prefix ): array {
		$opts = [ '' => __( '請選擇捐贈單位', 'mo-ectools' ) ];
		foreach ( self::donate_orgs( $option_prefix ) as $o ) {
			$opts[ $o['code'] ] = $o['name'];
		}
		return $opts;
	}

	/**
	 * 「捐贈單位」下拉（區塊結帳 additional field）的 options（[ ['value'=>愛心碼,'label'=>名稱], ... ]）。
	 * 不前置「請選擇」— WC 區塊 select 自己會加一個「選取…」placeholder，再加就變兩個。
	 */
	public static function donate_block_options( string $option_prefix ): array {
		$opts = [];
		foreach ( self::donate_orgs( $option_prefix ) as $o ) {
			$opts[] = [
				'value' => $o['code'],
				'label' => $o['name'],
			];
		}
		return $opts;
	}

	/** 由愛心碼反查捐贈單位名稱（找不到回空字串）— 已開立發票顯示用。 */
	public static function donate_org_name( string $option_prefix, string $code ): string {
		foreach ( self::donate_orgs( $option_prefix ) as $o ) {
			if ( $o['code'] === $code ) {
				return $o['name'];
			}
		}
		return '';
	}
}
