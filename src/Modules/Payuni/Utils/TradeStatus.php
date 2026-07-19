<?php
namespace Moksafowo\Modules\Payuni\Utils;

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\Payuni\Gateways\Aftee;
use Moksafowo\Modules\Payuni\Gateways\Atm;
use Moksafowo\Modules\Payuni\Gateways\Cvs;

class TradeStatus {

	const CREDIT_VALID_OR_GET_NUMBER_SUCCESS = '0'; // 信用審查正常或取號成功. (AFTEE, ATM, CVS)
	const PAID                               = '1'; // 已付款.
	const FAIL                               = '2'; // 付款失敗.
	const CANCEL                             = '3'; // 付款取消.
	const EXPIRED                            = '4'; // 交易逾期. (AFTEE, ATM, CVS)
	const TBC                                = '8'; // 待確認
	const UNPAID                             = '9'; // 未付款.

	public static function get_name( $status_code, $payment_method ) {
		switch ( $status_code ) {
			case self::CREDIT_VALID_OR_GET_NUMBER_SUCCESS:
				if ( $payment_method === Atm::GATEWAY_ID || $payment_method === Cvs::GATEWAY_ID ) {
					return _x( 'Payment Number Taken', 'Trade Status', 'moksa-for-woocommerce' );
				} elseif ( $payment_method === Aftee::GATEWAY_ID ) {
					return _x( 'Credit Valid', 'Trade Status', 'moksa-for-woocommerce' );
				} else {
					return _x( 'Credit Valid or Get Number Success', 'Trade Status', 'moksa-for-woocommerce' );
				}
			case self::PAID:
				return _x( 'Paid', 'Trade Status', 'moksa-for-woocommerce' );
			case self::FAIL:
				return _x( 'Payment Fail', 'Trade Status', 'moksa-for-woocommerce' );
			case self::CANCEL:
				return _x( 'Payment Cancel', 'Trade Status', 'moksa-for-woocommerce' );
			case self::EXPIRED:
				return _x( 'Transaction Expired', 'Trade Status', 'moksa-for-woocommerce' );
			case self::TBC:
				return _x( 'To be Confirmed', 'Trade Status', 'moksa-for-woocommerce' );
			case self::UNPAID:
				return _x( 'Unpaid', 'Trade Status', 'moksa-for-woocommerce' );
			default:
				return _x( 'Unknown Trade Status', 'Trade Status', 'moksa-for-woocommerce' );
		}
	}
}
