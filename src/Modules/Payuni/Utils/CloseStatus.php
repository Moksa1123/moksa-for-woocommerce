<?php
namespace Moksafowo\Modules\Payuni\Utils;

defined( 'ABSPATH' ) || exit;

class CloseStatus {

	const CAPTURE_APPLING    = '1'; // 請款申請中.
	const CAPTURE_OK         = '2'; // 請款成功.
	const CAPTURE_CANCELLED  = '3'; // 請款取消.
	const CAPTURE_PROCESSING = '7'; // 請款處理中.
	const CAPTURE_UNAPPLY    = '9'; // 未申請.

	public static function get_name( string $code ): string {
		switch ( $code ) {
			case self::CAPTURE_APPLING:
				return __( 'Capture requested', 'moksa-for-woocommerce' );
			case self::CAPTURE_OK:
				return __( 'Captured', 'moksa-for-woocommerce' );
			case self::CAPTURE_CANCELLED:
				return __( 'Capture cancelled', 'moksa-for-woocommerce' );
			case self::CAPTURE_PROCESSING:
				return __( 'Capture in progress', 'moksa-for-woocommerce' );
			case self::CAPTURE_UNAPPLY:
				return __( 'Not captured', 'moksa-for-woocommerce' );
			default:
				return $code;
		}
	}
}
