<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EzpayInvoice\Api;

use Moksafowo\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	// ezPay 沙箱無公開測試帳號 — 商家須自行至 https://cinv.ezpay.com.tw 註冊取得自家
	// 測試 MerchantID + HashKey + HashIV。文件 BDV/EZP_INVI 出現的範例值（3883991 等）
	// 經 v0.5.21 實測為 KEY10006 取得商店申請資格失敗 — 不可用，已移除避免誤導。

	public const BASE_SANDBOX = 'https://cinv.ezpay.com.tw';
	public const BASE_PROD    = 'https://inv.ezpay.com.tw';

	protected static function option_prefix(): string {
		return 'moksafowo_ezpay_invoice';
	}

	protected static function log_source(): string {
		return 'ezpay-invoice';
	}

	public static function is_sandbox(): bool {
		return 'yes' === get_option( 'moksafowo_ezpay_invoice_sandbox_enabled', 'no' );
	}

	public static function merchant_id(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_ezpay_invoice_sandbox_merchant_id', '' );
		}
		return (string) get_option( 'moksafowo_ezpay_invoice_merchant_id', '' );
	}

	public static function hash_key(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_ezpay_invoice_sandbox_hash_key', '' );
		}
		return (string) get_option( 'moksafowo_ezpay_invoice_hash_key', '' );
	}

	public static function hash_iv(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_ezpay_invoice_sandbox_hash_iv', '' );
		}
		return (string) get_option( 'moksafowo_ezpay_invoice_hash_iv', '' );
	}

	public static function base_url(): string {
		return self::is_sandbox() ? self::BASE_SANDBOX : self::BASE_PROD;
	}

	public static function order_prefix(): string {
		$raw = (string) get_option( 'moksafowo_ezpay_invoice_order_prefix', '' );
		$raw = preg_replace( '/[^A-Za-z0-9]/', '', $raw ) ?? '';
		return substr( $raw, 0, 5 );
	}

	public static function generate_merchant_order_no( int $order_id ): string {
		$prefix = self::order_prefix();
		$rand   = (string) random_int( 0, 9 );
		$rev_ts = strrev( (string) time() );
		return substr( $prefix . $order_id . 'TS' . $rand . $rev_ts, 0, 18 );
	}

	public static function parse_order_id( string $merchant_order_no ): ?int {
		$prefix  = self::order_prefix();
		$without = ( '' !== $prefix && str_starts_with( $merchant_order_no, $prefix ) )
			? substr( $merchant_order_no, strlen( $prefix ) )
			: $merchant_order_no;
		$ts_pos  = strpos( $without, 'TS' );
		if ( false === $ts_pos ) {
			return null;
		}
		$order_id_str = substr( $without, 0, $ts_pos );
		if ( ! ctype_digit( $order_id_str ) || '' === $order_id_str ) {
			return null;
		}
		$order_id = (int) $order_id_str;
		return $order_id > 0 ? $order_id : null;
	}

	public static function encrypt_post_data( array $args ): string {
		ksort( $args );
		$plain = http_build_query( $args );
		// Forward to plugin-wide Crypto\Aes：ezPay wire format（aes-256-cbc + bin2hex + 32-byte
		// PKCS#7 pad block）跟 Crypto\Aes::encrypt_cbc_hex 100% 介面對上，原本 in-place 自寫
		// pad + openssl_encrypt 的 implementation 改 forward 後消除重複。
		return \Moksafowo\Crypto\Aes::encrypt_cbc_hex( $plain, self::hash_key(), self::hash_iv() );
	}

	public static function decrypt_result( string $hex_cipher ): ?array {
		try {
			$plain = \Moksafowo\Crypto\Aes::decrypt_cbc_hex( $hex_cipher, self::hash_key(), self::hash_iv() );
		} catch ( \RuntimeException $e ) {
			return null;
		}
		parse_str( $plain, $out );
		return is_array( $out ) ? $out : null;
	}

	public static function check_value( string $post_data ): string {
		return strtoupper(
			hash(
				'sha256',
				'HashKey=' . self::hash_key() . '&' . $post_data . '&HashIV=' . self::hash_iv()
			)
		);
	}


	public static function post( string $path, array $args ) {
		try {
			$post_data = self::encrypt_post_data( $args );
		} catch ( \Throwable $e ) {
			self::log( 'encrypt failed', [ 'msg' => $e->getMessage() ] );
			return [
				'ok'      => false,
				'status'  => 'ENCRYPT_FAIL',
				'message' => $e->getMessage(),
			];
		}

		$body = [
			'MerchantID_' => self::merchant_id(),
			'PostData_'   => $post_data,
		];

		$response = wp_safe_remote_post(
			self::base_url() . $path,
			[
				'timeout' => 30,
				'body'    => $body,
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
				'message' => sprintf( __( 'ezPay 回傳 HTTP %d', 'moksa-for-woocommerce' ), $code ),
			];
		}

		$raw    = (string) wp_remote_retrieve_body( $response );
		$result = json_decode( $raw, true );
		if ( ! is_array( $result ) ) {
			return [
				'ok'      => false,
				'status'  => 'PARSE_FAIL',
				'message' => __( 'ezPay 回傳格式無法解析', 'moksa-for-woocommerce' ),
				'raw'     => $raw,
			];
		}

		$status  = (string) ( $result['Status'] ?? '' );
		$message = (string) ( $result['Message'] ?? '' );
		$inner   = isset( $result['Result'] ) ? (string) $result['Result'] : '';
		// ezPay Result 是 JSON string（不是加密），直接 decode
		$inner_decoded = '' !== $inner ? json_decode( $inner, true ) : null;

		self::log(
			'response',
			[
				'path'    => $path,
				'status'  => $status,
				'message' => $message,
			]
		);

		return [
			'ok'      => 'SUCCESS' === $status,
			'status'  => $status,
			'message' => $message,
			'data'    => is_array( $inner_decoded ) ? $inner_decoded : [],
			'raw'     => $raw,
		];
	}

	// is_sandbox / log_enabled / log inherited from AbstractCredentialHelper
}
