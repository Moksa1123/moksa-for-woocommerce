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
	public const ENDPOINT_TCAT_GET_TRACKNUM = 'http://ssl.smse.com.tw/api/ezcatGetTrackNum.asp';
	public const ENDPOINT_TCAT_PRINT        = 'https://ssl.smse.com.tw/api/ezcatPrintDelivery.asp';
	public const ENDPOINT_B2C_PRINT         = 'https://ssl.smse.com.tw/api/B2C_MultiplePrint.asp';
	public const ENDPOINT_C2B_PRINT         = 'http://ssl.smse.com.tw/api/C2BPayment.asp';
	public const ENDPOINT_LOGISTIC_EMAP     = 'https://ssl.smse.com.tw/api/LogisticsEmap.asp';
	public const ENDPOINT_MTMK              = 'https://ssl.smse.com.tw/ezpos/mtmk_utf.asp';        // 不用，留作參考

	protected static function option_prefix(): string {
		return 'moksafowo_smilepay_shipping';
	}

	protected static function log_source(): string {
		return 'smilepay-shipping';
	}

	public static function dcvc(): string {
		return (string) get_option( 'moksafowo_smilepay_shipping_dcvc', '' );
	}

	public static function rvg2c(): string {
		return (string) get_option( 'moksafowo_smilepay_shipping_rvg2c', '' );
	}

	public static function verify_key(): string {
		return (string) get_option( 'moksafowo_smilepay_shipping_verify_key', '' );
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
