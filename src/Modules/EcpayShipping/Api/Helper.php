<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Api;

use MoksaWeb\Mowc\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	// ECPay 公開測試帳號 — fallback 用，正式商家應於 Settings 填自己的
	public const SANDBOX_C2C_MERCHANT_ID = '2000933';
	public const SANDBOX_C2C_HASH_KEY    = 'XBERn1YOvpM9nfZc';
	public const SANDBOX_C2C_HASH_IV     = 'h1ONHk4P4yqbl5LK';
	public const SANDBOX_B2C_MERCHANT_ID = '2000132';
	public const SANDBOX_B2C_HASH_KEY    = '5294y06JbISpM5x9';
	public const SANDBOX_B2C_HASH_IV     = 'v77hoKGq4kWxNNIS';

	public const ENDPOINT_SANDBOX_CREATE = 'https://logistics-stage.ecpay.com.tw/Express/Create';
	public const ENDPOINT_PROD_CREATE    = 'https://logistics.ecpay.com.tw/Express/Create';
	public const ENDPOINT_SANDBOX_MAP    = 'https://logistics-stage.ecpay.com.tw/Express/map';
	public const ENDPOINT_PROD_MAP       = 'https://logistics.ecpay.com.tw/Express/map';

	protected static function option_prefix(): string {
		return 'moksafowo_ecpay_shipping';
	}

	protected static function log_source(): string {
		return 'ecpay-shipping';
	}

	public static function group_for_subtype( string $subtype ): string {
		return ( '' !== $subtype && str_ends_with( $subtype, 'C2C' ) ) ? 'c2c' : 'b2c';
	}

	public static function merchant_id( string $subtype = 'UNIMARTC2C' ): string {
		return self::cred( $subtype, 'merchant_id' );
	}

	public static function hash_key( string $subtype = 'UNIMARTC2C' ): string {
		return self::cred( $subtype, 'hash_key' );
	}

	public static function hash_iv( string $subtype = 'UNIMARTC2C' ): string {
		return self::cred( $subtype, 'hash_iv' );
	}

	private static function cred( string $subtype, string $field ): string {
		$group     = self::group_for_subtype( $subtype );
		$is_test   = self::is_sandbox();
		$prefix    = 'moksafowo_ecpay_shipping_' . $group . '_' . ( $is_test ? 'sandbox_' : '' );
		$opt_key   = $prefix . $field;
		$value     = (string) get_option( $opt_key, '' );

		// Fallback 1：legacy 單組設定（沒有 _c2c_/_b2c_ 前綴）— migrate 用
		if ( '' === $value ) {
			$legacy_key = 'moksafowo_ecpay_shipping_' . ( $is_test ? 'sandbox_' : '' ) . $field;
			$value      = (string) get_option( $legacy_key, '' );
		}

		// Fallback 2：sandbox 公開測試帳號（避免 user 還沒填就完全爆）
		if ( '' === $value && $is_test ) {
			$consts = [
				'c2c' => [
					'merchant_id' => self::SANDBOX_C2C_MERCHANT_ID,
					'hash_key'    => self::SANDBOX_C2C_HASH_KEY,
					'hash_iv'     => self::SANDBOX_C2C_HASH_IV,
				],
				'b2c' => [
					'merchant_id' => self::SANDBOX_B2C_MERCHANT_ID,
					'hash_key'    => self::SANDBOX_B2C_HASH_KEY,
					'hash_iv'     => self::SANDBOX_B2C_HASH_IV,
				],
			];
			$value = $consts[ $group ][ $field ] ?? '';
		}

		return $value;
	}

	public static function create_endpoint(): string {
		return self::is_sandbox() ? self::ENDPOINT_SANDBOX_CREATE : self::ENDPOINT_PROD_CREATE;
	}

	public static function map_endpoint(): string {
		return self::is_sandbox() ? self::ENDPOINT_SANDBOX_MAP : self::ENDPOINT_PROD_MAP;
	}

	public static function has_credentials_for( string $group ): bool {
		$is_test  = self::is_sandbox();
		$prefix   = 'moksafowo_ecpay_shipping_' . $group . '_' . ( $is_test ? 'sandbox_' : '' );
		$mid      = (string) get_option( $prefix . 'merchant_id', '' );
		// legacy fallback
		if ( '' === $mid ) {
			$legacy = 'moksafowo_ecpay_shipping_' . ( $is_test ? 'sandbox_' : '' ) . 'merchant_id';
			$mid    = (string) get_option( $legacy, '' );
		}
		return '' !== $mid;
	}

	public static function generate_merchant_trade_no( int $order_id ): string {
		$prefix = 'mowpL';
		$random = bin2hex( random_bytes( 3 ) );
		$base   = $prefix . str_pad( (string) $order_id, 6, '0', STR_PAD_LEFT ) . 'R' . $random;
		return substr( $base, 0, 20 );
	}

	public static function generate_check_mac_value( array $data, string $subtype = 'UNIMARTC2C' ): string {
		$svc = new \Ecpay\Sdk\Services\CheckMacValueService(
			self::hash_key( $subtype ),
			self::hash_iv( $subtype ),
			\Ecpay\Sdk\Services\CheckMacValueService::METHOD_MD5
		);
		return $svc->generate( $data );
	}

	public static function verify_check_mac_value( array $posted ): bool {
		if ( empty( $posted['CheckMacValue'] ) ) {
			return false;
		}
		$signed = $posted;
		unset( $signed['CheckMacValue'] );
		$subtype  = (string) ( $posted['LogisticsSubType'] ?? 'UNIMARTC2C' );
		$expected = self::generate_check_mac_value( $signed, $subtype );
		return hash_equals( $expected, (string) $posted['CheckMacValue'] );
	}

	// is_sandbox / log_enabled / log inherited from AbstractCredentialHelper
}
