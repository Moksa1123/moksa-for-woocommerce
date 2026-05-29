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
		// 純數字 fast path（含小數）
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
			' mo-temps="%s" mo-qty="%d" mo-weight="%s"',
			esc_attr( (string) $temps ),
			$qty,
			esc_attr( (string) $weight )
		);

		// 把屬性注入到 [mo_addfee ...] / [ry_addfee ...] 標籤的開頭
		// 用 lookahead 確保只 match shortcode 開頭，避免動到屬性中含 mo_addfee 字樣的字串
		$sum = (string) preg_replace( '/\[(mo_addfee|ry_addfee)(?=[\s\]])/', '[$1' . $injection, $formula );

		// do_shortcode 解 [mo_addfee ...] → 數字字串
		$sum = do_shortcode( $sum );

		// WC 內建表達式引擎，安全 — 不會吃到 PHP eval
		if ( ! class_exists( '\WC_Eval_Math' ) ) {
			$wc_path = function_exists( 'WC' ) ? WC()->plugin_path() : '';
			$lib     = $wc_path . '/includes/libraries/class-wc-eval-math.php';
			if ( $wc_path && file_exists( $lib ) ) {
				include_once $lib;
			}
		}

		if ( ! class_exists( '\WC_Eval_Math' ) ) {
			// WC_Eval_Math 缺席 fallback：只接受純數字
			return is_numeric( $sum ) ? (float) $sum : 0.0;
		}

		$result = \WC_Eval_Math::evaluate( (string) $sum );
		return is_numeric( $result ) ? (float) $result : 0.0;
	}
}
