<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\AmegoInvoice\Api;

use Moksafowo\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;


final class Helper extends AbstractCredentialHelper {

	public const ENDPOINT = 'https://invoice-api.amego.tw';

	// Amego 公開測試憑證（spec §基本說明 揭示）
	public const SANDBOX_INVOICE_ID = '12345678';
	public const SANDBOX_APP_KEY    = 'sHeq7t8G1wiQvhAuIM27';

	protected static function option_prefix(): string {
		return 'moksafowo_amego_invoice';
	}

	protected static function log_source(): string {
		return 'amego-invoice';
	}

	public static function invoice_id(): string {
		if ( self::is_sandbox() ) {
			$v = (string) get_option( 'moksafowo_amego_invoice_sandbox_invoice_id', '' );
			return '' !== $v ? $v : self::SANDBOX_INVOICE_ID;
		}
		return (string) get_option( 'moksafowo_amego_invoice_invoice_id', '' );
	}

	public static function app_key(): string {
		if ( self::is_sandbox() ) {
			$v = (string) get_option( 'moksafowo_amego_invoice_sandbox_app_key', '' );
			return '' !== $v ? $v : self::SANDBOX_APP_KEY;
		}
		return (string) get_option( 'moksafowo_amego_invoice_app_key', '' );
	}

	public static function has_credentials(): bool {
		return '' !== self::invoice_id() && '' !== self::app_key();
	}


	public static function sign( string $data_json, int $time ): string {
		return md5( $data_json . $time . self::app_key() );
	}


	public static function generate_order_id( int $order_id ): string {
		$prefix = (string) get_option( 'moksafowo_amego_invoice_order_prefix', '' );
		$prefix = preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) ?? '';
		$rand   = bin2hex( random_bytes( 3 ) );
		return substr( $prefix . $order_id . 'R' . $rand, 0, 40 );
	}
}
