<?php

namespace Moksafowo\Modules\Payuni\Utils;

defined( 'ABSPATH' ) || exit;

class BankType {

	const TWBank     = '004';
	const CTBCBank   = '822';
	const CATHAYBank = '013';

	public static function get_name( $bank_type ) {
		switch ( $bank_type ) {
			case self::TWBank:
				return __( 'Taiwan Bank', 'moksa-for-woocommerce' );
			case self::CTBCBank:
				return __( 'CTBC Bank', 'moksa-for-woocommerce' );
			case self::CATHAYBank:
				return __( 'Cathay Bank', 'moksa-for-woocommerce' );
			default:
				return __( 'Unknown Bank', 'moksa-for-woocommerce' );
		}
	}
}
