<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PaynowInvoice\Api;

use MoksaWeb\Mowc\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	public const ENDPOINT_PROD = 'https://invoice.paynow.com.tw/PayNowEInvoice.asmx';
	public const ENDPOINT_TEST = 'https://testinvoice.paynow.com.tw/PayNowEInvoice.asmx';

	protected static function option_prefix(): string {
		return 'moksafowo_paynow_invoice';
	}

	protected static function log_source(): string {
		return 'paynow-invoice';
	}

	public static function endpoint(): string {
		return self::is_sandbox() ? self::ENDPOINT_TEST : self::ENDPOINT_PROD;
	}

	public static function mem_cid(): string {
		$key = self::is_sandbox() ? 'moksafowo_paynow_invoice_sandbox_mem_cid' : 'moksafowo_paynow_invoice_mem_cid';
		return (string) get_option( $key, '' );
	}

	public static function mem_password(): string {
		$key = self::is_sandbox() ? 'moksafowo_paynow_invoice_sandbox_mem_password' : 'moksafowo_paynow_invoice_mem_password';
		return (string) get_option( $key, '' );
	}

	public static function has_credentials(): bool {
		return '' !== self::mem_cid() && '' !== self::mem_password();
	}


	public static function generate_orderno( int $order_id ): string {
		$prefix = (string) get_option( 'moksafowo_paynow_invoice_orderno_prefix', '' );
		$prefix = preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) ?? '';
		$random = bin2hex( random_bytes( 3 ) );
		return substr( $prefix . $order_id . 'R' . $random, 0, 30 );
	}
}
