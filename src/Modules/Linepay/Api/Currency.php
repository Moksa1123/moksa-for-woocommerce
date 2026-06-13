<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay\Api;

defined( 'ABSPATH' ) || exit;

final class Currency {

	public static function get_standardized( $amount, ?string $currency = null ): string {
		$scale = self::get_currency_scale( $currency );
		if ( is_string( $amount ) ) {
			$amount = (float) $amount;
		}
		return number_format( (float) $amount, $scale, '.', '' );
	}

	public static function get_currency_scale( ?string $currency_code = null ): int {
		if ( null === $currency_code ) {
			$currency_code = get_woocommerce_currency();
		}
		$currency_code = strtoupper( $currency_code );
		// 用 array_key_exists 而非 in_array — 修 wpbr-moksafowo-linepay 1.3.3 對非 TWD 幣別錯誤匹配的 bug
		if ( array_key_exists( $currency_code, \MoksaWeb\Mowc\Modules\Linepay\LinePay::$currency_scales ) ) {
			return (int) \MoksaWeb\Mowc\Modules\Linepay\LinePay::$currency_scales[ $currency_code ];
		}
		return 0;
	}

	public static function valid_currency_scale( $amount, ?string $currency_code = null ): bool {
		return self::get_currency_scale( $currency_code ) >= self::get_amount_precision( $amount );
	}

	public static function get_amount_precision( $amount = 0 ): int {
		if ( is_string( $amount ) ) {
			$amount = (float) $amount;
		}
		$str  = (string) $amount;
		$len  = strlen( $str );
		$dot  = strpos( $str, '.' );
		$frac = ( false !== $dot ) ? $dot + 1 : $len;
		return $len - $frac;
	}
}
