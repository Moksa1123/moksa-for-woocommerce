<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Shortcodes;

defined( 'ABSPATH' ) || exit;

final class AddFee {

	public static function init(): void {
		add_shortcode( 'mo_addfee', [ self::class, 'render' ] );
		// RY pro 平移用 alias — 商家從 RY-Tools-Pro 搬過來時，既有設定的
		// `[ry_addfee ...]` 直接就跑得動。
		add_shortcode( 'ry_addfee', [ self::class, 'render' ] );
	}

	public static function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'cool'      => '0',
				'cool_2'    => '0',
				'cool_3'    => '0',
				'temp_1'    => '0',
				'temp_2'    => '0',
				'temp_3'    => '0',
				'qty'       => '0',
				'weight'    => '0',
				// runtime injection from EvaluateCost
				'mo-temps'  => '',
				'mo-qty'    => '0',
				'mo-weight' => '0',
			],
			is_array( $atts ) ? $atts : [],
			'mo_addfee'
		);

		$temps = '' !== $atts['mo-temps']
			? array_filter( array_map( 'intval', explode( ',', $atts['mo-temps'] ) ) )
			: [];
		$qty    = max( 0, (int) $atts['mo-qty'] );
		$weight = max( 0.0, (float) $atts['mo-weight'] );

		$add = 0.0;

		// per-temp 基本費：給「依溫層分別計算運費」用 — 每個溫層各帶一份 base。
		// 配 AbstractShippingMethod::evaluate_cost_for_temp 模式（mo-temps 只含 1 個 temp）
		// 時最有意義：常溫 cart → temp_1 fire / 冷藏 cart → temp_2 fire / 冷凍 cart → temp_3 fire。
		// 老 toggle OFF 模式下 mo-temps 含多 temp，多個 temp_N 會同時 fire（= 各溫層基本費直加）。
		if ( in_array( 1, $temps, true ) && (float) $atts['temp_1'] > 0 ) {
			$add += (float) $atts['temp_1'];
		}
		if ( in_array( 2, $temps, true ) && (float) $atts['temp_2'] > 0 ) {
			$add += (float) $atts['temp_2'];
		}
		if ( in_array( 3, $temps, true ) && (float) $atts['temp_3'] > 0 ) {
			$add += (float) $atts['temp_3'];
		}
		// 任一冷的就加 (cool)
		if ( ( in_array( 2, $temps, true ) || in_array( 3, $temps, true ) ) && (float) $atts['cool'] > 0 ) {
			$add += (float) $atts['cool'];
		}
		// 冷藏 (2) 額外加
		if ( in_array( 2, $temps, true ) && (float) $atts['cool_2'] > 0 ) {
			$add += (float) $atts['cool_2'];
		}
		// 冷凍 (3) 額外加
		if ( in_array( 3, $temps, true ) && (float) $atts['cool_3'] > 0 ) {
			$add += (float) $atts['cool_3'];
		}
		// 每件加（qty 屬性 × 件數）
		if ( $qty > 0 && (float) $atts['qty'] > 0 ) {
			$add += (float) $atts['qty'] * $qty;
		}
		// 每公斤加（weight 屬性 × 重量）
		if ( $weight > 0 && (float) $atts['weight'] > 0 ) {
			$add += (float) $atts['weight'] * $weight;
		}

		// WC_Eval_Math 接受字串數字
		return (string) $add;
	}
}
