<?php

namespace Moksafowo\Modules\Payuni\Utils;

defined( 'ABSPATH' ) || exit;

class PayType {

	const CREDIT_CARD = 1; // 信用卡
	const ATM         = 2; // ATM轉帳
	const CVS_CODE    = 3; // 代碼
	const C2C         = 5; // 貨到付款(超商取貨付款)
	const ICASH       = 6; // 愛金卡 (ICash)
	const AFTEE       = 7; // 後支付 (Aftee)
	const LINEPAY     = 9; // LinePay
	const DELIVERY    = 10; // 宅配到付

	public static function get_name( $pay_type ) {
		switch ( $pay_type ) {
			case self::CREDIT_CARD:
				return __( 'Credit Card', 'moksa-for-woocommerce' );
			case self::ATM:
				return __( 'ATM', 'moksa-for-woocommerce' );
			case self::CVS_CODE:
				return __( '超商代碼', 'moksa-for-woocommerce' );
			case self::C2C:
				return __( 'C2C', 'moksa-for-woocommerce' );
			case self::ICASH:
				return __( 'ICash', 'moksa-for-woocommerce' );
			case self::AFTEE:
				return __( 'AFTEE', 'moksa-for-woocommerce' );
			case self::LINEPAY:
				return __( 'LINE Pay', 'moksa-for-woocommerce' );
			case self::DELIVERY:
				return __( 'Delivery', 'moksa-for-woocommerce' );
			default:
				return __( 'Unknown Payment Type', 'moksa-for-woocommerce' );
		}
	}
}
