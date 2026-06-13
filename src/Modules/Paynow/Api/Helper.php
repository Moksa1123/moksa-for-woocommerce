<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Paynow\Api;

use MoksaWeb\Mowc\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	// PayNow 沒公開測試帳號 — 商家須自行向立即富申請測試 / 正式商店。
	public const ENDPOINT_SANDBOX = 'https://test.paynow.com.tw/service/etopm.aspx';
	public const ENDPOINT_PROD    = 'https://www.paynow.com.tw/service/etopm.aspx';

	protected static function option_prefix(): string {
		return 'moksafowo_paynow';
	}

	protected static function log_source(): string {
		return 'paynow-payment';
	}

	public static function is_sandbox(): bool {
		return 'yes' === get_option( 'moksafowo_paynow_sandbox_enabled', 'no' );
	}

	public static function web_no(): string {
		$raw = self::is_sandbox()
			? (string) get_option( 'moksafowo_paynow_sandbox_web_no', '' )
			: (string) get_option( 'moksafowo_paynow_web_no', '' );
		return trim( $raw );
	}

	public static function trade_password(): string {
		return self::is_sandbox()
			? (string) get_option( 'moksafowo_paynow_sandbox_trade_password', '' )
			: (string) get_option( 'moksafowo_paynow_trade_password', '' );
	}

	public static function ec_platform(): string {
		$raw = trim( (string) get_option( 'moksafowo_paynow_ec_platform', '' ) );
		if ( '' !== $raw ) {
			return $raw;
		}
		return (string) get_bloginfo( 'name' );
	}

	public static function has_credentials(): bool {
		return '' !== self::web_no() && '' !== self::trade_password();
	}

	public static function endpoint(): string {
		return self::is_sandbox() ? self::ENDPOINT_SANDBOX : self::ENDPOINT_PROD;
	}

	public static function notify_url(): string {
		return home_url( '/wc-api/moksafowo_paynow_payment' );
	}

	public static function generate_order_no( int $order_id ): string {
		$rand = bin2hex( random_bytes( 2 ) );
		return preg_replace( '/[^A-Za-z0-9]/', '', (string) $order_id . 'R' . $rand ) ?? (string) $order_id;
	}

	public static function parse_order_id( string $order_no ): ?int {
		if ( ! preg_match( '/^(\d+)R/', $order_no, $m ) && ! preg_match( '/^(\d+)$/', $order_no, $m ) ) {
			return null;
		}
		$id = (int) $m[1];
		return $id > 0 ? $id : null;
	}
}
