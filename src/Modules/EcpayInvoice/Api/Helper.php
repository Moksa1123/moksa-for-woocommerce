<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayInvoice\Api;

use MoksaWeb\Mowc\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	// ECPay 發票公開測試帳號（ecpay-official 官方 SDK demo）
	public const SANDBOX_MERCHANT_ID = '2000132';
	public const SANDBOX_HASH_KEY    = 'ejCk326UnaZWKisg';
	public const SANDBOX_HASH_IV     = 'q9jcZX8Ib9LM8wYk';

	public const BASE_SANDBOX = 'https://einvoice-stage.ecpay.com.tw';
	public const BASE_PROD    = 'https://einvoice.ecpay.com.tw';

	protected static function option_prefix(): string {
		return 'mo_ecpay_invoice';
	}

	protected static function log_source(): string {
		return 'ecpay-invoice';
	}

	public static function merchant_id(): string {
		if ( self::is_sandbox() ) {
			$v = (string) get_option( 'mo_ecpay_invoice_sandbox_merchant_id', '' );
			return '' !== $v ? $v : self::SANDBOX_MERCHANT_ID;
		}
		return (string) get_option( 'mo_ecpay_invoice_merchant_id', '' );
	}

	public static function hash_key(): string {
		if ( self::is_sandbox() ) {
			$v = (string) get_option( 'mo_ecpay_invoice_sandbox_hash_key', '' );
			return '' !== $v ? $v : self::SANDBOX_HASH_KEY;
		}
		return (string) get_option( 'mo_ecpay_invoice_hash_key', '' );
	}

	public static function hash_iv(): string {
		if ( self::is_sandbox() ) {
			$v = (string) get_option( 'mo_ecpay_invoice_sandbox_hash_iv', '' );
			return '' !== $v ? $v : self::SANDBOX_HASH_IV;
		}
		return (string) get_option( 'mo_ecpay_invoice_hash_iv', '' );
	}

	public static function base_url(): string {
		return self::is_sandbox() ? self::BASE_SANDBOX : self::BASE_PROD;
	}

	public static function endpoint( string $path ): string {
		return self::base_url() . $path;
	}

	public static function encrypt( string $plaintext ): string {
		$key = self::hash_key();
		$iv  = self::hash_iv();
		// 先 url-encode plaintext（ECPay spec），再 AES-128-CBC，最後 base64
		$urlencoded = urlencode( $plaintext );
		$cipher     = openssl_encrypt( $urlencoded, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			throw new \RuntimeException( 'ECPay invoice AES encrypt failed' );
		}
		return base64_encode( $cipher );
	}

	public static function decrypt( string $b64_cipher ): string {
		$key = self::hash_key();
		$iv  = self::hash_iv();
		$raw = base64_decode( $b64_cipher, true );
		if ( false === $raw ) {
			throw new \RuntimeException( 'ECPay invoice AES decrypt: bad base64' );
		}
		$plain = openssl_decrypt( $raw, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $plain ) {
			throw new \RuntimeException( 'ECPay invoice AES decrypt failed' );
		}
		return urldecode( $plain );
	}

	public static function generate_relate_number( int $order_id ): string {
		$user_prefix = preg_replace( '/[^A-Za-z0-9]/', '', (string) get_option( 'mo_ecpay_invoice_prefix', '' ) );
		$user_prefix = substr( (string) $user_prefix, 0, 5 );
		$prefix      = '' !== $user_prefix ? $user_prefix . 'INV' : 'mowpINV';
		$random      = bin2hex( random_bytes( 4 ) );
		$base        = $prefix . str_pad( (string) $order_id, 6, '0', STR_PAD_LEFT ) . 'R' . $random;
		return substr( $base, 0, 30 );
	}

	public static function rq_header(): array {
		return [
			'Timestamp' => time(),
			'Revision'  => '3.0.0',
		];
	}

	
	public static function post( string $path, array $data ): array {
		try {
			$plain  = wp_json_encode( $data );
			if ( false === $plain ) {
				return [ 'ok' => false, 'message' => 'json_encode failed' ];
			}
			$cipher = self::encrypt( $plain );
		} catch ( \Throwable $e ) {
			self::log( 'invoice encrypt failed', [ 'message' => $e->getMessage() ] );
			return [ 'ok' => false, 'message' => $e->getMessage() ];
		}

		$payload = [
			'PlatformID' => '',
			'MerchantID' => self::merchant_id(),
			'RqHeader'   => self::rq_header(),
			'Data'       => $cipher,
		];

		$response = wp_safe_remote_post( self::endpoint( $path ), [
			'timeout' => 30,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $payload ),
		] );
		if ( is_wp_error( $response ) ) {
			self::log( 'invoice POST wp_error', [ 'path' => $path, 'msg' => $response->get_error_message() ] );
			return [ 'ok' => false, 'message' => $response->get_error_message() ];
		}

		$raw = (string) wp_remote_retrieve_body( $response );
		self::log( 'invoice POST response', [ 'path' => $path, 'raw' => $raw ] );

		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) || ! isset( $json['Data'] ) ) {
			return [ 'ok' => false, 'message' => 'unexpected response shape', 'raw' => $raw ];
		}

		try {
			$plain_data = self::decrypt( (string) $json['Data'] );
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'message' => $e->getMessage(), 'raw' => $raw ];
		}

		$decoded = json_decode( $plain_data, true );
		if ( ! is_array( $decoded ) ) {
			return [ 'ok' => false, 'message' => 'decoded data not array', 'raw' => $plain_data ];
		}

		$rtn_code = (int) ( $decoded['RtnCode'] ?? 0 );
		return [
			'ok'      => 1 === $rtn_code,
			'message' => (string) ( $decoded['RtnMsg'] ?? '' ),
			'data'    => $decoded,
		];
	}

	
	public static function check_barcode( string $barcode ): array {
		// 沒填或格式錯就不送 API（節省呼叫）
		if ( '' === $barcode ) {
			return [ 'ok' => false, 'exists' => false, 'code' => '1040', 'message' => __( '請輸入手機條碼。', 'mo-ectools' ) ];
		}
		// ECPay 規格：/ + 7 字元（大小寫字母 / 數字 / + - .）
		if ( ! preg_match( '#^/[0-9a-zA-Z+\-.]{7}$#', $barcode ) ) {
			return [ 'ok' => false, 'exists' => false, 'code' => '1041', 'message' => __( '手機條碼格式錯誤（需 / 開頭 + 7 碼字母/數字/+ - .）。', 'mo-ectools' ) ];
		}

		$result = self::post( '/B2CInvoice/CheckBarcode', [
			'MerchantID' => self::merchant_id(),
			'BarCode'    => $barcode,
		] );

		// 財政部 API 維護中（RtnCode=9000001）— 視為「驗不出來」，放行避免擋單
		$inner_code = (int) ( $result['data']['RtnCode'] ?? 0 );
		if ( 9000001 === $inner_code ) {
			self::log( 'CheckBarcode 財政部維護中 — 跳過驗證放行', [ 'barcode' => $barcode ] );
			return [ 'ok' => true, 'exists' => true, 'code' => '1042', 'message' => __( '財政部系統維護中，已跳過載具驗證。', 'mo-ectools' ) ];
		}

		// HTTP / 解密失敗
		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'exists' => false, 'code' => '1049', 'message' => $result['message'] ?? __( '驗證失敗。', 'mo-ectools' ) ];
		}

		$is_exist = (string) ( $result['data']['IsExist'] ?? '' );
		if ( 'Y' !== $is_exist ) {
			return [ 'ok' => true, 'exists' => false, 'code' => '1043', 'message' => __( '此手機條碼不存在，請確認是否輸入正確。', 'mo-ectools' ) ];
		}

		return [ 'ok' => true, 'exists' => true, 'code' => '1', 'message' => '' ];
	}

	
	public static function check_love_code( string $love_code ): array {
		if ( '' === $love_code ) {
			return [ 'ok' => false, 'exists' => false, 'code' => '1020', 'message' => __( '請輸入愛心碼。', 'mo-ectools' ) ];
		}
		if ( ! preg_match( '#^([xX][0-9]{2,6}|[0-9]{3,7})$#', $love_code ) ) {
			return [ 'ok' => false, 'exists' => false, 'code' => '1021', 'message' => __( '愛心碼格式錯誤（需 3-7 碼數字，或 X + 2-6 碼數字）。', 'mo-ectools' ) ];
		}

		$result = self::post( '/B2CInvoice/CheckLoveCode', [
			'MerchantID' => self::merchant_id(),
			'LoveCode'   => $love_code,
		] );

		$inner_code = (int) ( $result['data']['RtnCode'] ?? 0 );
		if ( 9000001 === $inner_code ) {
			self::log( 'CheckLoveCode 財政部維護中 — 跳過驗證放行', [ 'love_code' => $love_code ] );
			return [ 'ok' => true, 'exists' => true, 'code' => '1022', 'message' => __( '財政部系統維護中，已跳過愛心碼驗證。', 'mo-ectools' ) ];
		}

		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'exists' => false, 'code' => '1029', 'message' => $result['message'] ?? __( '驗證失敗。', 'mo-ectools' ) ];
		}

		$is_exist = (string) ( $result['data']['IsExist'] ?? '' );
		if ( 'Y' !== $is_exist ) {
			return [ 'ok' => true, 'exists' => false, 'code' => '1023', 'message' => __( '此愛心碼不存在，請確認是否輸入正確。', 'mo-ectools' ) ];
		}

		return [ 'ok' => true, 'exists' => true, 'code' => '1', 'message' => '' ];
	}

	// is_sandbox / log_enabled / log inherited from AbstractCredentialHelper
}
