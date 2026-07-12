<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Invoice;

defined( 'ABSPATH' ) || exit;


final class Ubn {

	public static function is_valid( string $ubn ): bool {
		if ( ! preg_match( '/^[0-9]{8}$/', $ubn ) ) {
			return false;
		}
		$weights = [ 1, 2, 1, 2, 1, 2, 4, 1 ];
		$digits  = str_split( $ubn );
		$sum     = 0;
		for ( $i = 0; $i < 8; $i++ ) {
			$product = (int) $digits[ $i ] * $weights[ $i ];
			$sum    += intdiv( $product, 10 ) + ( $product % 10 );
		}
		if ( 0 === $sum % 5 ) {
			return true;
		}
		return '7' === $digits[6] && 0 === ( $sum + 1 ) % 5;
	}
}
