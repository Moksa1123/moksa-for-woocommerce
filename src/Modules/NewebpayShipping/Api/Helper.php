<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\NewebpayShipping\Api;

use Moksafowo\Order\Meta\Keys;

use Moksafowo\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;


final class Helper extends AbstractCredentialHelper {

	protected static function option_prefix(): string {
		return 'moksafowo_newebpay_shipping';
	}

	protected static function log_source(): string {
		return 'newebpay-shipping';
	}

	/** 物流類型的預設值；設定欄位與所有讀取點共用，沒存過設定時兩邊才一致。 */
	public const LGS_TYPE_DEFAULT = 'C2C';

	public static function lgs_type(): string {
		return 'B2C' === (string) get_option( 'moksafowo_newebpay_shipping_lgs_type', self::LGS_TYPE_DEFAULT ) ? 'B2C' : 'C2C';
	}

	public static function is_sandbox(): bool {
		$raw = get_option( 'moksafowo_newebpay_shipping_sandbox_enabled', null );
		if ( null === $raw ) {
			// 物流設定頁還沒存過：跟隨藍新金流的測試模式（直接讀設定，不需要金流模組有啟用）。
			return 'yes' === get_option( 'moksafowo_newebpay_sandbox_enabled', 'no' );
		}
		return 'yes' === $raw;
	}

	public static function merchant_id(): string {
		return self::cred( 'merchant_id' );
	}

	public static function hash_key(): string {
		return self::cred( 'hash_key' );
	}

	public static function hash_iv(): string {
		return self::cred( 'hash_iv' );
	}

	/**
	 * 物流自己的憑證沒填就沿用藍新金流的；兩邊都依物流的測試開關挑同一組（測試或正式），
	 * 網址與憑證才會一致。
	 */
	private static function cred( string $field ): string {
		$sandbox = self::is_sandbox() ? 'sandbox_' : '';
		$val     = (string) get_option( 'moksafowo_newebpay_shipping_' . $sandbox . $field, '' );
		if ( '' !== $val ) {
			return $val;
		}
		return (string) get_option( 'moksafowo_newebpay_' . $sandbox . $field, '' );
	}

	public static function verify_trade_sha( string $trade_info, string $trade_sha ): bool {
		// 金鑰沒填時用空字串也算得出簽章、解得開密文，任何人都能偽造貨態通知。
		if ( '' === self::hash_key() || '' === self::hash_iv() ) {
			return false;
		}
		$expected = strtoupper( hash( 'sha256', 'HashKey=' . self::hash_key() . '&' . $trade_info . '&HashIV=' . self::hash_iv() ) );
		return hash_equals( $expected, strtoupper( $trade_sha ) );
	}

	public static function decrypt_trade_info( string $trade_info ): ?array {
		if ( '' === self::hash_key() || '' === self::hash_iv() ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- remote hex (TradeInfo) — malformed input returns false, validated below; @ suppresses the warning so the hex2bin return value can be validated explicitly.
		$bin = @hex2bin( $trade_info );
		if ( false === $bin ) {
			return null;
		}
		$plain = openssl_decrypt( $bin, 'aes-256-cbc', self::hash_key(), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, self::hash_iv() );
		if ( false === $plain ) {
			return null;
		}
		$pad = ord( substr( $plain, -1 ) );
		if ( $pad > 0 && $pad <= 16 ) {
			$plain = substr( $plain, 0, -$pad );
		}
		$json = json_decode( $plain, true );
		if ( is_array( $json ) ) {
			return $json;
		}
		// 物流 API 的請求是 query string 加密；回傳／通知若同格式也要能讀。
		parse_str( $plain, $pairs );
		return ! empty( $pairs ) ? $pairs : null;
	}


	public static function parse_order_id( string $merchant_order_no ): int {
		// 建單時已把 MerchantOrderNo 存進訂單，直接用它找最準；解析字串會受前綴與訂單號位數影響。
		$ids = wc_get_orders(
			[
				'limit'      => 1,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'status'     => 'all',
				'meta_key'   => Keys::NEWEBPAY_SHIPPING_MERCHANT_ORDER_NO, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off lookup per shipping notification.
				'meta_value' => $merchant_order_no, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
			]
		);
		if ( ! empty( $ids ) ) {
			return (int) $ids[0];
		}
		// 物流單號格式是「前綴＋訂單號＋R＋6 碼亂數」，跟金流的前綴設定無關，所以先剝掉物流前綴自己解析。
		$prefix = preg_replace( '/[^A-Za-z0-9]/', '', (string) get_option( 'moksafowo_newebpay_shipping_order_prefix', '' ) ) ?? '';
		$rest   = ( '' !== $prefix && str_starts_with( $merchant_order_no, $prefix ) ) ? substr( $merchant_order_no, strlen( $prefix ) ) : $merchant_order_no;
		if ( preg_match( '/^(\d+)R[0-9a-f]+$/i', $rest, $m ) ) {
			return (int) $m[1];
		}
		$external = apply_filters( 'moksafowo_newebpay_shipping_parse_order_id', null, $merchant_order_no );
		if ( null !== $external ) {
			return (int) $external;
		}
		// Fallback parser — NewebPay merchant_order_no 內含 6-digit 訂單 id
		if ( preg_match( '/(\d{6})R[a-f0-9]+/i', $merchant_order_no, $m ) ) {
			$id = (int) ltrim( $m[1], '0' );
			return $id > 0 ? $id : 0;
		}
		return 0;
	}
}
