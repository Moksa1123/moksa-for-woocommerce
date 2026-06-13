<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Api;

use MoksaWeb\Mowc\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	// NewebPay 沒公開測試帳號 — 商家須自行至 https://www.newebpay.com 申請會員開立商店
	public const BASE_SANDBOX = 'https://ccore.newebpay.com';
	public const BASE_PROD    = 'https://core.newebpay.com';

	public const PATH_MPG    = '/MPG/mpg_gateway';
	public const PATH_QUERY  = '/API/QueryTradeInfo';
	public const PATH_CLOSE  = '/API/CreditCard/Close';

	protected static function option_prefix(): string {
		return 'moksafowo_newebpay';
	}

	protected static function log_source(): string {
		return 'newebpay-payment';
	}

	public static function merchant_id(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_newebpay_sandbox_merchant_id', '' );
		}
		return (string) get_option( 'moksafowo_newebpay_merchant_id', '' );
	}

	public static function hash_key(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_newebpay_sandbox_hash_key', '' );
		}
		return (string) get_option( 'moksafowo_newebpay_hash_key', '' );
	}

	public static function hash_iv(): string {
		if ( self::is_sandbox() ) {
			return (string) get_option( 'moksafowo_newebpay_sandbox_hash_iv', '' );
		}
		return (string) get_option( 'moksafowo_newebpay_hash_iv', '' );
	}

	public static function base_url(): string {
		return self::is_sandbox() ? self::BASE_SANDBOX : self::BASE_PROD;
	}

	public static function mpg_url(): string {
		return self::base_url() . self::PATH_MPG;
	}

	public static function query_url(): string {
		return self::base_url() . self::PATH_QUERY;
	}

	public static function close_url(): string {
		return self::base_url() . self::PATH_CLOSE;
	}

	public static function order_prefix(): string {
		$raw = (string) get_option( 'moksafowo_newebpay_order_prefix', '' );
		$raw = preg_replace( '/[^A-Za-z0-9]/', '', $raw ) ?? '';
		return substr( $raw, 0, 5 );
	}

	public static function generate_merchant_order_no( int $order_id ): string {
		$prefix = self::order_prefix();
		$rand   = bin2hex( random_bytes( 2 ) );
		return substr( $prefix . $order_id . 'R' . $rand, 0, 20 );
	}

	public static function parse_order_id( string $merchant_order_no ): ?int {
		$prefix  = self::order_prefix();
		$without = ( '' !== $prefix && str_starts_with( $merchant_order_no, $prefix ) )
			? substr( $merchant_order_no, strlen( $prefix ) )
			: $merchant_order_no;
		// pre-R numeric portion = order_id
		if ( ! preg_match( '/^(\d+)R/', $without, $m ) ) {
			return null;
		}
		$order_id = (int) $m[1];
		return $order_id > 0 ? $order_id : null;
	}

	public static function encrypt_trade_info( array $args ): string {
		ksort( $args );
		$plain  = http_build_query( $args );
		$cipher = openssl_encrypt(
			$plain,
			'aes-256-cbc',
			self::hash_key(),
			OPENSSL_RAW_DATA,
			self::hash_iv()
		);
		if ( false === $cipher ) {
			throw new \RuntimeException( 'NewebPay AES-256-CBC encrypt failed' );
		}
		return bin2hex( $cipher );
	}

	public static function decrypt_trade_info( string $hex ): ?array {
		$bin = @hex2bin( $hex );
		if ( false === $bin ) {
			return null;
		}
		$plain = openssl_decrypt(
			$bin,
			'aes-256-cbc',
			self::hash_key(),
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
			self::hash_iv()
		);
		if ( false === $plain ) {
			return null;
		}
		// PKCS#7 manual unpad
		$slast  = ord( substr( $plain, -1 ) );
		$slastc = chr( $slast );
		if ( preg_match( '/' . preg_quote( $slastc, '/' ) . '{' . $slast . '}$/', $plain ) ) {
			$plain = substr( $plain, 0, -$slast );
		}
		// NewebPay 回 JSON string 包 TradeInfo decrypted 內容
		$json = json_decode( $plain, true );
		if ( is_array( $json ) ) {
			return $json;
		}
		// fallback: parse_str（form-encoded）
		parse_str( $plain, $out );
		return is_array( $out ) ? $out : null;
	}

	public static function generate_trade_sha( string $hex_trade_info ): string {
		return strtoupper( hash(
			'sha256',
			'HashKey=' . self::hash_key() . '&' . $hex_trade_info . '&HashIV=' . self::hash_iv()
		) );
	}

	public static function verify_trade_sha( string $trade_info_hex, string $trade_sha ): bool {
		$expected = self::generate_trade_sha( $trade_info_hex );
		return hash_equals( $expected, strtoupper( $trade_sha ) );
	}

	
	public static function generate_query_check_value( array $args ): string {
		$str = http_build_query( [
			'Amt'             => $args['Amt'],
			'MerchantID'      => $args['MerchantID'],
			'MerchantOrderNo' => $args['MerchantOrderNo'],
		] );
		return strtoupper( hash(
			'sha256',
			'IV=' . self::hash_iv() . '&' . $str . '&Key=' . self::hash_key()
		) );
	}

	
	public static function generate_notify_check_code( array $args ): string {
		$pick = [
			'Amt'             => $args['Amt'],
			'MerchantID'      => $args['MerchantID'],
			'MerchantOrderNo' => $args['MerchantOrderNo'],
			'TradeNo'         => $args['TradeNo'],
		];
		ksort( $pick );
		$str = http_build_query( $pick );
		return strtoupper( hash(
			'sha256',
			'HashIV=' . self::hash_iv() . '&' . $str . '&HashKey=' . self::hash_key()
		) );
	}

	public static function is_sandbox(): bool {
		return 'yes' === get_option( 'moksafowo_newebpay_sandbox_enabled', 'no' );
	}

	// log_enabled / log inherited from AbstractCredentialHelper
}
