<?php

namespace MoksaWeb\Mowc\Modules\Payuni\Utils;

defined( 'ABSPATH' ) || exit;

class BankType {

    const TWBank   = '004';
    const CTBCBank = '822';
    const CATHAYBank = '013';

    public static function get_name( $bank_type ) {
        switch ( $bank_type ) {
            case self::TWBank:
                return __( 'Taiwan Bank', 'mo-ectools' );
            case self::CTBCBank:
                return __( 'CTBC Bank', 'mo-ectools' );
            case self::CATHAYBank:
                return __( 'Cathay Bank', 'mo-ectools' );
            default:
                return __( 'Unknown Bank', 'mo-ectools' );
        }
    }
}
