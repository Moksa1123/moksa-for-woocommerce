<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Api;

use Moksafowo\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	public const BASE_SANDBOX = 'https://ccore.newebpay.com';
	public const BASE_PROD    = 'https://core.newebpay.com';

	public const PATH_MPG   = '/MPG/mpg_gateway';
	public const PATH_QUERY = '/API/QueryTradeInfo';
	public const PATH_CLOSE = '/API/CreditCard/Close';

	protected static function option_prefix(): string {
		return 'moksafowo_newebpay';
	}

	protected static function log_source(): string {
		return 'newebpay-payment';
	}

	/** 正式模式下憑證沒填時，付款方式不顯示（否則顧客選了才失敗）。 */
	public static function has_credentials(): bool {
		return '' !== self::merchant_id() && '' !== self::hash_key() && '' !== self::hash_iv();
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

	/** 記下送出過的單號；重試會覆蓋目前單號，舊的取號代碼付款時還要對得到這張訂單。 */
	public static function remember_mtn( \WC_Order $order, string $mtn ): void {
		$order->update_meta_data( \Moksafowo\Order\Meta\Keys::NEWEBPAY_MERCHANT_ORDER_NO, $mtn );
		$history = $order->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_MTN_HISTORY );
		$history = is_array( $history ) ? $history : [];
		if ( ! in_array( $mtn, $history, true ) ) {
			$history[] = $mtn;
			$order->update_meta_data( \Moksafowo\Order\Meta\Keys::NEWEBPAY_MTN_HISTORY, array_slice( $history, -20 ) );
		}
	}

	/**
	 * 通知帶來的單號對應哪張訂單。先查目前單號；用解析出的編號時要確認那張訂單真的送過這個單號，
	 * 不然商店改過數字前綴後，剩下的數字會被當成另一張訂單。
	 */
	public static function resolve_order_id( string $mtn ): ?int {
		if ( '' === $mtn ) {
			return null;
		}
		$found = \Moksafowo\Order\Lookup::by_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_MERCHANT_ORDER_NO, $mtn );
		if ( null !== $found ) {
			return $found;
		}
		// 單號 = 前綴（0～5 碼）＋訂單編號＋R＋亂數。前綴可能改過，每種剝法都試，
		// 只採用單號清單裡真的有這個單號的訂單。
		$cut  = strrpos( $mtn, 'R' );
		$head = false !== $cut ? substr( $mtn, 0, $cut ) : '';
		$max  = min( 5, strlen( $head ) - 1 );
		for ( $strip = 0; $strip <= $max; $strip++ ) {
			$candidate = substr( $head, $strip );
			if ( ! ctype_digit( $candidate ) || '0' === $candidate[0] ) {
				continue;
			}
			$order   = wc_get_order( (int) $candidate );
			$history = $order instanceof \WC_Order ? $order->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_MTN_HISTORY ) : null;
			if ( is_array( $history ) && in_array( $mtn, $history, true ) ) {
				return (int) $candidate;
			}
		}

		// 這版之前建立的訂單沒有單號清單，只能照舊用目前前綴解析。
		$parsed = self::parse_order_id( $mtn );
		$order  = null !== $parsed ? wc_get_order( $parsed ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		$history = $order->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_MTN_HISTORY );
		return is_array( $history ) && ! empty( $history ) ? null : $parsed;
	}

	/** 藍新交易金額：查詢與退款的 Amt 要等於當初付款的金額，訂單總額改過也一樣。 */
	public static function paid_amount( \WC_Order $order ): int {
		$paid = (int) $order->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_PAID_AMT );
		return $paid > 0 ? $paid : (int) ceil( (float) $order->get_total() );
	}

	public static function parse_order_id( string $merchant_order_no ): ?int {
		$prefix  = self::order_prefix();
		$without = ( '' !== $prefix && str_starts_with( $merchant_order_no, $prefix ) )
			? substr( $merchant_order_no, strlen( $prefix ) )
			: $merchant_order_no;
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
		// 定期定額通知只靠加密證明來源；金鑰沒填時用空字串也解得開，任何人都能偽造。
		if ( '' === self::hash_key() || '' === self::hash_iv() ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- remote hex (TradeInfo) — malformed input returns false, validated below; @ suppresses the warning so the hex2bin return value can be validated explicitly.
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
		$slast  = ord( substr( $plain, -1 ) );
		$slastc = chr( $slast );
		if ( preg_match( '/' . preg_quote( $slastc, '/' ) . '{' . $slast . '}$/', $plain ) ) {
			$plain = substr( $plain, 0, -$slast );
		}
		$json = json_decode( $plain, true );
		if ( is_array( $json ) ) {
			return $json;
		}
		parse_str( $plain, $out );
		return is_array( $out ) ? $out : null;
	}

	public static function generate_trade_sha( string $hex_trade_info ): string {
		return strtoupper(
			hash(
				'sha256',
				'HashKey=' . self::hash_key() . '&' . $hex_trade_info . '&HashIV=' . self::hash_iv()
			)
		);
	}

	public static function verify_trade_sha( string $trade_info_hex, string $trade_sha ): bool {
		// 金鑰沒填時用空字串也算得出簽章，任何人都能偽造。
		if ( '' === self::hash_key() || '' === self::hash_iv() ) {
			return false;
		}
		$expected = self::generate_trade_sha( $trade_info_hex );
		return hash_equals( $expected, strtoupper( $trade_sha ) );
	}


	public static function generate_query_check_value( array $args ): string {
		$str = http_build_query(
			[
				'Amt'             => $args['Amt'],
				'MerchantID'      => $args['MerchantID'],
				'MerchantOrderNo' => $args['MerchantOrderNo'],
			]
		);
		return strtoupper(
			hash(
				'sha256',
				'IV=' . self::hash_iv() . '&' . $str . '&Key=' . self::hash_key()
			)
		);
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
		return strtoupper(
			hash(
				'sha256',
				'HashIV=' . self::hash_iv() . '&' . $str . '&HashKey=' . self::hash_key()
			)
		);
	}

	public static function is_sandbox(): bool {
		return 'yes' === get_option( 'moksafowo_newebpay_sandbox_enabled', 'no' );
	}
}
