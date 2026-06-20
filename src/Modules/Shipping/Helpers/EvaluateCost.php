<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Helpers;

defined( 'ABSPATH' ) || exit;

final class EvaluateCost {


	public static function evaluate( string $formula, array $args = [] ): float {
		$formula = trim( $formula );
		if ( '' === $formula ) {
			return 0.0;
		}
		if ( is_numeric( $formula ) ) {
			return (float) $formula;
		}

		$temps = $args['temps'] ?? [];
		if ( is_array( $temps ) ) {
			$temps = implode( ',', array_map( 'intval', $temps ) );
		}
		$qty    = (int) ( $args['qty'] ?? 0 );
		$weight = (float) ( $args['weight'] ?? 0 );

		$injection = sprintf(
			' moksafowo-temps="%s" moksafowo-qty="%d" moksafowo-weight="%s"',
			esc_attr( (string) $temps ),
			$qty,
			esc_attr( (string) $weight )
		);

		// 舊 token（ry_addfee / mo_addfee）先改寫成現行 shortcode，維持既有運費公式相容（只註冊 moksafowo_addfee）。
		$formula = (string) preg_replace( '/\[(?:ry|mo)_addfee(?=[\s\]])/', '[moksafowo_addfee', $formula );
		$sum     = (string) preg_replace( '/\[(moksafowo_addfee)(?=[\s\]])/', '[$1' . $injection, $formula );
		$sum     = do_shortcode( $sum );

		// WC_Eval_Math 安全 — 不 eval PHP
		if ( ! class_exists( '\WC_Eval_Math' ) ) {
			$wc_path = function_exists( 'WC' ) ? WC()->plugin_path() : '';
			$lib     = $wc_path . '/includes/libraries/class-wc-eval-math.php';
			if ( $wc_path && file_exists( $lib ) ) {
				include_once $lib;
			}
		}

		if ( ! class_exists( '\WC_Eval_Math' ) ) {
			return is_numeric( $sum ) ? (float) $sum : 0.0;
		}

		$result = \WC_Eval_Math::evaluate( (string) $sum );
		return is_numeric( $result ) ? (float) $result : 0.0;
	}
}
