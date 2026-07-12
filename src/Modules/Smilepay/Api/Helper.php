<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Smilepay\Api;

use Moksafowo\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	// SmilePay 金流 endpoint — sandbox / production 共用 host，由 Dcvc 區分測試/正式商家。
	public const ENDPOINT_SP_API = 'https://ssl.smse.com.tw/api/SPPayment.asp';   // 取號式（ATM / 條碼 / ibon / FamiPort）server POST。
	public const ENDPOINT_MTMK   = 'https://ssl.smse.com.tw/ezpos/mtmk_utf.asp';  // 跳轉式（信用卡 / 分期 / 銀聯）GET redirect。

	// 官方外掛內建公開測試商家（測試模式啟用時用）。可由 wp-config 常數覆寫。
	public const SANDBOX_DCVC       = '107';
	public const SANDBOX_RVG2C      = '1';
	public const SANDBOX_VERIFY_KEY = '174A02F97A95F72CE301137B3F98D128';
	public const SANDBOX_MID        = '1111';

	protected static function option_prefix(): string {
		return 'moksafowo_smilepay';
	}

	protected static function log_source(): string {
		return 'smilepay-payment';
	}

	public static function is_sandbox(): bool {
		return 'yes' === get_option( 'moksafowo_smilepay_sandbox_enabled', 'no' );
	}

	public static function dcvc(): string {
		if ( self::is_sandbox() ) {
			return defined( 'MO_SMILEPAY_SANDBOX_DCVC' ) ? (string) MO_SMILEPAY_SANDBOX_DCVC : self::SANDBOX_DCVC;
		}
		return (string) get_option( 'moksafowo_smilepay_dcvc', '' );
	}

	public static function rvg2c(): string {
		if ( self::is_sandbox() ) {
			return defined( 'MO_SMILEPAY_SANDBOX_RVG2C' ) ? (string) MO_SMILEPAY_SANDBOX_RVG2C : self::SANDBOX_RVG2C;
		}
		return (string) get_option( 'moksafowo_smilepay_rvg2c', '' );
	}

	public static function verify_key(): string {
		if ( self::is_sandbox() ) {
			return defined( 'MO_SMILEPAY_SANDBOX_VERIFY_KEY' ) ? (string) MO_SMILEPAY_SANDBOX_VERIFY_KEY : self::SANDBOX_VERIFY_KEY;
		}
		return (string) get_option( 'moksafowo_smilepay_verify_key', '' );
	}

	public static function mid(): string {
		if ( self::is_sandbox() ) {
			return defined( 'MO_SMILEPAY_SANDBOX_MID' ) ? (string) MO_SMILEPAY_SANDBOX_MID : self::SANDBOX_MID;
		}
		return (string) get_option( 'moksafowo_smilepay_mid', '' );
	}

	public static function has_credentials(): bool {
		return '' !== self::dcvc() && '' !== self::rvg2c() && '' !== self::verify_key();
	}

	public static function calc_mid_smilepay( string $mid, string $amount, string $smseid ): string {
		$r_all = substr( $smseid, -4, 4 );
		$r_all = str_pad( $r_all, 4, '9', STR_PAD_LEFT );
		$r     = '';
		for ( $i = 0; $i < 4; $i++ ) {
			$ch = substr( $r_all, $i, 1 );
			$r .= is_numeric( $ch ) ? $ch : '9';
		}

		$str1 = str_pad( $amount, 8, '0', STR_PAD_LEFT );
		$str  = $mid . $str1 . $r;

		$odd  = 0;
		$even = 0;
		for ( $i = 0; $i < 16; $i++ ) {
			$digit = (int) substr( $str, $i, 1 );
			if ( 0 === $i % 2 ) {
				$even += $digit;
			} else {
				$odd += $digit;
			}
		}
		return (string) ( $even * 9 + $odd * 3 );
	}

	public static function big5_to_utf8( string $string ): string {
		if ( '' === $string ) {
			return '';
		}
		// SmilePay 的 UTF callback endpoint（與我方自帶的 Payment_title query param）已是 UTF-8，
		// 再丟進 Big5 解碼會雙重解碼成亂碼。只有非合法 UTF-8（= 真 Big5）才轉。
		if ( mb_check_encoding( $string, 'UTF-8' ) ) {
			return $string;
		}
		$converted = mb_convert_encoding( $string, 'UTF-8', 'BIG-5' );
		return is_string( $converted ) ? $converted : $string;
	}

	public static function build_products_summary( \WC_Order $order, int $max_len ): string {
		$parts = [];
		foreach ( $order->get_items() as $item ) {
			$parts[] = $item->get_name() . '*' . $item->get_quantity();
		}
		$joined = trim( implode( '｜', $parts ), '｜' );
		return mb_substr( $joined, 0, $max_len, 'UTF-8' );
	}
}
