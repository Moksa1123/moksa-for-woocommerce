<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Api;

use Moksafowo\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	// SmilePay 全部 API endpoint — sandbox / production 共用同一個 host，由 Dcvc 區分測試/正式商家
	public const ENDPOINT_SP_API            = 'https://ssl.smse.com.tw/api/SPPayment.asp';        // 主 step 1 取序號
	public const ENDPOINT_C2C_API           = 'https://ssl.smse.com.tw/api/C2CPayment.asp';       // C2C 超商取貨付款 confirm
	public const ENDPOINT_C2CU_API          = 'https://ssl.smse.com.tw/api/C2CPaymentU.asp';      // C2C 超商純取貨 confirm
	public const ENDPOINT_B2C_API           = 'https://ssl.smse.com.tw/api/B2CPayment.asp';       // B2C 超商 confirm
	public const ENDPOINT_TCAT_GET_TRACKNUM = 'https://ssl.smse.com.tw/api/ezcatGetTrackNum.asp';
	public const ENDPOINT_TCAT_PRINT        = 'https://ssl.smse.com.tw/api/ezcatPrintDelivery.asp';
	public const ENDPOINT_B2C_PRINT         = 'https://ssl.smse.com.tw/api/B2C_MultiplePrint.asp';
	public const ENDPOINT_C2B_PRINT         = 'https://ssl.smse.com.tw/api/C2BPayment.asp';
	public const ENDPOINT_LOGISTIC_EMAP     = 'https://ssl.smse.com.tw/api/LogisticsEmap.asp';
	public const ENDPOINT_MTMK              = 'https://ssl.smse.com.tw/ezpos/mtmk_utf.asp';        // 不用，留作參考

	protected static function option_prefix(): string {
		return 'moksafowo_smilepay_shipping';
	}

	protected static function log_source(): string {
		return 'smilepay-shipping';
	}

	// 速買配沒有獨立的測試網址：測試模式就是用官方公開測試帳號打同一組 API（不會真的出貨）。
	public const SANDBOX_DCVC       = '107';
	public const SANDBOX_RVG2C      = '1';
	public const SANDBOX_VERIFY_KEY = '174A02F97A95F72CE301137B3F98D128';

	/** 預設值跟設定頁一致（不勾）；共用基底預設是 yes，沒存過設定的站會被誤判成測試模式。 */
	public static function is_sandbox(): bool {
		return 'yes' === get_option( 'moksafowo_smilepay_shipping_sandbox_enabled', 'no' );
	}

	public static function dcvc(): string {
		return self::is_sandbox() ? self::SANDBOX_DCVC : (string) get_option( 'moksafowo_smilepay_shipping_dcvc', '' );
	}

	public static function rvg2c(): string {
		return self::is_sandbox() ? self::SANDBOX_RVG2C : (string) get_option( 'moksafowo_smilepay_shipping_rvg2c', '' );
	}

	/**
	 * 速買配的貨態通知不帶任何密鑰（Verify_key 是我們送出去的），只能靠回呼網址上的每單 key 驗來源。
	 */
	public static function callback_key( \WC_Order $order ): string {
		return substr( hash_hmac( 'sha256', 'smilepay-shipping-roturl|' . $order->get_id() . '|' . $order->get_order_key(), wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function keyed_roturl( \WC_Order $order ): string {
		return add_query_arg( 'mfk', self::callback_key( $order ), home_url( '/wc-api/moksafowo_smilepay_shipping_status' ) );
	}

	public static function verify_key(): string {
		return self::is_sandbox() ? self::SANDBOX_VERIFY_KEY : (string) get_option( 'moksafowo_smilepay_shipping_verify_key', '' );
	}

	public static function smseid(): string {
		return (string) get_option( 'moksafowo_smilepay_shipping_smseid', '' );
	}

	public static function cvs_service_type(): string {
		return 'B2C' === get_option( 'moksafowo_smilepay_shipping_cvs_service_type', 'C2C' ) ? 'B2C' : 'C2C';
	}


	public static function sender_info(): array {
		return [
			'name'    => trim( (string) get_option( 'moksafowo_smilepay_shipping_sender_name', '' ) ),
			'phone'   => trim( (string) get_option( 'moksafowo_smilepay_shipping_sender_phone', '' ) ),
			'email'   => trim( (string) get_option( 'moksafowo_smilepay_shipping_sender_email', '' ) ),
			'address' => trim( (string) get_option( 'moksafowo_smilepay_shipping_sender_address', '' ) ),
		];
	}
}
