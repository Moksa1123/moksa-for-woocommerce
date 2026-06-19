<?php
namespace MoksaWeb\Mowc\Modules\Payuni\Utils;

defined( 'ABSPATH' ) || exit;

use MoksaWeb\Mowc\Modules\Payuni\Gateways\Aftee;
use MoksaWeb\Mowc\Modules\Payuni\Gateways\Atm;
use MoksaWeb\Mowc\Modules\Payuni\Gateways\Cvs;

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
					return _x( 'Payment Number Taken', 'Trade Status', 'mo-ectools' );
				} elseif ( $payment_method === Aftee::GATEWAY_ID ) {
					return _x( 'Credit Valid', 'Trade Status', 'mo-ectools' );
				} else {
					return _x( 'Credit Valid or Get Number Success', 'Trade Status', 'mo-ectools' );
				}
			case self::PAID:
				return _x( 'Paid', 'Trade Status', 'mo-ectools' );
			case self::FAIL:
				return _x( 'Payment Fail', 'Trade Status', 'mo-ectools' );
			case self::CANCEL:
				return _x( 'Payment Cancel', 'Trade Status', 'mo-ectools' );
			case self::EXPIRED:
				return _x( 'Transaction Expired', 'Trade Status', 'mo-ectools' );
			case self::TBC:
				return _x( 'To be Confirmed', 'Trade Status', 'mo-ectools' );
			case self::UNPAID:
				return _x( 'Unpaid', 'Trade Status', 'mo-ectools' );
			default:
				return _x( 'Unknown Trade Status', 'Trade Status', 'mo-ectools' );
		}
	}
}
