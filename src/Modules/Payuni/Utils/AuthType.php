<?php
namespace Moksafowo\Modules\Payuni\Utils;

defined( 'ABSPATH' ) || exit;

class AuthType {

	const ONE         = '1'; // 一次性支付.
	const INSTALLMENT = '2'; // 分期支付.
	const POINT       = '3'; // 點數支付.
	const APPLEPAY    = '4'; // Apple Pay.
	const GOOGLEPAY   = '5'; // Google Pay.
	const SAMSUNGPAY  = '6'; // Samsung Pay.
	const UNIPAY      = '7'; // 銀聯

	public static function get_type( $type ) {
		switch ( $type ) {
			case self::ONE:
				return __( 'One-time payment', 'moksa-for-woocommerce' );
			case self::INSTALLMENT:
				return __( 'Instalments', 'moksa-for-woocommerce' );
			case self::POINT:
				return __( 'Reward points', 'moksa-for-woocommerce' );
			case self::APPLEPAY:
				return 'Apple Pay';
			case self::GOOGLEPAY:
				return 'Google Pay';
			case self::SAMSUNGPAY:
				return 'Samsung Pay';
			case self::UNIPAY:
				return __( 'UnionPay', 'moksa-for-woocommerce' );
			default:
				return __( 'Unknown authorisation type', 'moksa-for-woocommerce' );
		}
	}
}
