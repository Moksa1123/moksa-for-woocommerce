<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayInvoice\Api;

use MoksaWeb\Mowc\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	// SmilePay 沙箱沒「公開測試帳號」，商家自行去 https://www.smilepay.net 申請
	public const BASE_SANDBOX = 'https://ssl.smse.com.tw/api_test';
	public const BASE_PROD    = 'https://ssl.smse.com.tw/api';

	public const PATH_ISSUE   = '/SPEinvoice_Storage.asp';
	public const PATH_INVALID = '/SPEinvoice_Storage_Modify.asp';

	protected static function option_prefix(): string {
		return 'moksafowo_smilepay_invoice';
	}

	protected static function log_source(): string {
		return 'smilepay-invoice';
	}

	public static function grvc(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_smilepay_invoice_sandbox_grvc', '' );
		}
		return (string) get_option( 'moksafowo_smilepay_invoice_grvc', '' );
	}

	public static function verify_key(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_smilepay_invoice_sandbox_verify_key', '' );
		}
		return (string) get_option( 'moksafowo_smilepay_invoice_verify_key', '' );
	}

	public static function base_url(): string {
		return self::is_sandbox() ? self::BASE_SANDBOX : self::BASE_PROD;
	}

	public static function track_system_id(): string {
		return (string) get_option( 'moksafowo_smilepay_invoice_track_system_id', '' );
	}

	public static function order_prefix(): string {
		$raw = (string) get_option( 'moksafowo_smilepay_invoice_order_prefix', '' );
		$raw = preg_replace( '/[^A-Za-z0-9]/', '', $raw ) ?? '';
		return substr( $raw, 0, 5 );
	}

	public static function generate_order_id( int $order_id ): string {
		$prefix = self::order_prefix();
		$rand   = (string) random_int( 0, 9 );
		$rev_ts = strrev( (string) time() );
		return substr( $prefix . $order_id . 'TS' . $rand . $rev_ts, 0, 18 );
	}

	public static function parse_order_id( string $smilepay_order_id ): ?int {
		$prefix  = self::order_prefix();
		$without = ( '' !== $prefix && str_starts_with( $smilepay_order_id, $prefix ) )
			? substr( $smilepay_order_id, strlen( $prefix ) )
			: $smilepay_order_id;
		$ts_pos  = strpos( $without, 'TS' );
		if ( false === $ts_pos ) {
			return null;
		}
		$id_str = substr( $without, 0, $ts_pos );
		if ( ! ctype_digit( $id_str ) || '' === $id_str ) {
			return null;
		}
		$order_id = (int) $id_str;
		return $order_id > 0 ? $order_id : null;
	}


	public static function post( string $path, array $args ): array {
		$response = wp_safe_remote_post(
			self::base_url() . $path,
			[
				'timeout' => 30,
				'body'    => $args,
			]
		);
		if ( is_wp_error( $response ) ) {
			self::log( 'http error: ' . $response->get_error_message(), [ 'path' => $path ] );
			return [
				'ok'      => false,
				'status'  => 'HTTP_ERROR',
				'message' => $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return [
				'ok'      => false,
				'status'  => 'HTTP_' . $code,
				/* translators: %d: HTTP response code */
				'message' => sprintf( __( 'SmilePay 回傳 HTTP %d', 'mo-ectools' ), $code ),
			];
		}

		$raw = (string) wp_remote_retrieve_body( $response );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- remote XML response — malformed input returns false, validated below; @ suppresses the warning so the simplexml return value can be validated explicitly.
		$xml = @simplexml_load_string( $raw );
		if ( ! is_object( $xml ) ) {
			return [
				'ok'      => false,
				'status'  => 'PARSE_FAIL',
				'message' => __( 'SmilePay 回傳格式無法解析', 'mo-ectools' ),
				'data'    => [ 'raw' => $raw ],
			];
		}

		// SmilePay XML 回應：<Status>0</Status> 為成功，<Desc> 為訊息
		$status = (string) ( $xml->Status ?? '' );
		$desc   = (string) ( $xml->Desc ?? '' );

		// XML → array shallow flatten
		$data = [];
		foreach ( $xml->children() as $key => $val ) {
			$data[ (string) $key ] = (string) $val;
		}

		self::log(
			'response',
			[
				'path'   => $path,
				'status' => $status,
				'desc'   => $desc,
			]
		);

		return [
			'ok'      => '0' === $status,
			'status'  => $status,
			'message' => $desc,
			'data'    => $data,
		];
	}

	public static function is_sandbox(): bool {
		return 'yes' === get_option( 'moksafowo_smilepay_invoice_sandbox_enabled', 'no' );
	}

	// log_enabled / log inherited from AbstractCredentialHelper
}
