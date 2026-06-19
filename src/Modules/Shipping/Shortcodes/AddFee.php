<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Shortcodes;

defined( 'ABSPATH' ) || exit;

final class AddFee {

	public static function init(): void {
		add_shortcode( 'moksafowo_addfee', [ self::class, 'render' ] );
		add_shortcode( 'ry_addfee', [ self::class, 'render' ] ); // RY pro 平移 alias
		add_shortcode( 'mo_addfee', [ self::class, 'render' ] ); // 2026-06 前綴遷移前已存的 formula
	}

	public static function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'cool'             => '0',
				'cool_2'           => '0',
				'cool_3'           => '0',
				'temp_1'           => '0',
				'temp_2'           => '0',
				'temp_3'           => '0',
				'qty'              => '0',
				'weight'           => '0',
				// runtime injection from EvaluateCost
				'moksafowo-temps'  => '',
				'moksafowo-qty'    => '0',
				'moksafowo-weight' => '0',
			],
			is_array( $atts ) ? $atts : [],
			'moksafowo_addfee'
		);

		$temps  = '' !== $atts['moksafowo-temps']
			? array_filter( array_map( 'intval', explode( ',', $atts['moksafowo-temps'] ) ) )
			: [];
		$qty    = max( 0, (int) $atts['moksafowo-qty'] );
		$weight = max( 0.0, (float) $atts['moksafowo-weight'] );

		$add = 0.0;

		// per-temp 基本費：evaluate_cost_for_temp 模式下 mo-temps 只含 1 個 temp（常溫/冷藏/冷凍各 fire 一次）
		if ( in_array( 1, $temps, true ) && (float) $atts['temp_1'] > 0 ) {
			$add += (float) $atts['temp_1'];
		}
		if ( in_array( 2, $temps, true ) && (float) $atts['temp_2'] > 0 ) {
			$add += (float) $atts['temp_2'];
		}
		if ( in_array( 3, $temps, true ) && (float) $atts['temp_3'] > 0 ) {
			$add += (float) $atts['temp_3'];
		}
		if ( ( in_array( 2, $temps, true ) || in_array( 3, $temps, true ) ) && (float) $atts['cool'] > 0 ) {
			$add += (float) $atts['cool'];
		}
		if ( in_array( 2, $temps, true ) && (float) $atts['cool_2'] > 0 ) {
			$add += (float) $atts['cool_2'];
		}
		if ( in_array( 3, $temps, true ) && (float) $atts['cool_3'] > 0 ) {
			$add += (float) $atts['cool_3'];
		}
		if ( $qty > 0 && (float) $atts['qty'] > 0 ) {
			$add += (float) $atts['qty'] * $qty;
		}
		if ( $weight > 0 && (float) $atts['weight'] > 0 ) {
			$add += (float) $atts['weight'] * $weight;
		}

		return (string) $add;
	}
}
