<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Tests\Unit;

use MoksaWeb\Mowc\Modules\Shipping\Shortcodes\AddFee;
use PHPUnit\Framework\TestCase;

final class AddFeeShortcodeTest extends TestCase {

	private static function eval_atts( array $atts ): float {
		return (float) AddFee::render( $atts );
	}

	private static function eval_for_temps( array $temps, array $extra = [] ): float {
		return self::eval_atts( array_merge( [ 'mo-temps' => implode( ',', $temps ) ], $extra ) );
	}

	public function test_empty_atts_returns_zero(): void {
		self::assertSame( 0.0, self::eval_atts( [] ) );
	}

	public function test_temp_1_fires_only_when_cart_has_temp_1(): void {
		self::assertSame( 100.0, self::eval_for_temps( [ 1 ], [ 'temp_1' => '100' ] ) );
		self::assertSame( 0.0, self::eval_for_temps( [ 2 ], [ 'temp_1' => '100' ] ) );
		self::assertSame( 0.0, self::eval_for_temps( [ 3 ], [ 'temp_1' => '100' ] ) );
	}

	public function test_temp_2_fires_only_when_cart_has_temp_2(): void {
		self::assertSame( 0.0, self::eval_for_temps( [ 1 ], [ 'temp_2' => '150' ] ) );
		self::assertSame( 150.0, self::eval_for_temps( [ 2 ], [ 'temp_2' => '150' ] ) );
		self::assertSame( 0.0, self::eval_for_temps( [ 3 ], [ 'temp_2' => '150' ] ) );
	}

	public function test_temp_3_fires_only_when_cart_has_temp_3(): void {
		self::assertSame( 0.0, self::eval_for_temps( [ 1 ], [ 'temp_3' => '200' ] ) );
		self::assertSame( 0.0, self::eval_for_temps( [ 2 ], [ 'temp_3' => '200' ] ) );
		self::assertSame( 200.0, self::eval_for_temps( [ 3 ], [ 'temp_3' => '200' ] ) );
	}

	public function test_three_temps_in_cart_sums_all_temp_N(): void {
		// per-temp 階梯：cart [1,2,3] 三個 temp_N 都 fire
		$atts = [ 'temp_1' => '100', 'temp_2' => '150', 'temp_3' => '200' ];
		self::assertSame( 450.0, self::eval_for_temps( [ 1, 2, 3 ], $atts ) );
	}

	public function test_per_temp_eval_pattern_split_mode(): void {
		// 模擬 SplitByTemp ON 模式：mo-temps 只含 1 個 temp，依序拿到該溫層 base
		$atts = [ 'temp_1' => '100', 'temp_2' => '150', 'temp_3' => '200' ];
		self::assertSame( 100.0, self::eval_for_temps( [ 1 ], $atts ) );
		self::assertSame( 150.0, self::eval_for_temps( [ 2 ], $atts ) );
		self::assertSame( 200.0, self::eval_for_temps( [ 3 ], $atts ) );
	}

	public function test_cool_fires_when_any_cold_temp(): void {
		self::assertSame( 0.0, self::eval_for_temps( [ 1 ], [ 'cool' => '60' ] ) );
		self::assertSame( 60.0, self::eval_for_temps( [ 2 ], [ 'cool' => '60' ] ) );
		self::assertSame( 60.0, self::eval_for_temps( [ 3 ], [ 'cool' => '60' ] ) );
		// cool 只加一次，不重複加（多個 cool temp 一起）
		self::assertSame( 60.0, self::eval_for_temps( [ 2, 3 ], [ 'cool' => '60' ] ) );
	}

	public function test_cool_2_fires_only_for_refrigerated(): void {
		self::assertSame( 0.0, self::eval_for_temps( [ 1 ], [ 'cool_2' => '50' ] ) );
		self::assertSame( 50.0, self::eval_for_temps( [ 2 ], [ 'cool_2' => '50' ] ) );
		self::assertSame( 0.0, self::eval_for_temps( [ 3 ], [ 'cool_2' => '50' ] ) );
	}

	public function test_cool_3_fires_only_for_frozen(): void {
		self::assertSame( 0.0, self::eval_for_temps( [ 1 ], [ 'cool_3' => '100' ] ) );
		self::assertSame( 0.0, self::eval_for_temps( [ 2 ], [ 'cool_3' => '100' ] ) );
		self::assertSame( 100.0, self::eval_for_temps( [ 3 ], [ 'cool_3' => '100' ] ) );
	}

	public function test_qty_multiplies_by_cart_quantity(): void {
		self::assertSame( 50.0, self::eval_atts( [ 'qty' => '10', 'mo-qty' => '5' ] ) );
		// qty 屬性 0 → 不加
		self::assertSame( 0.0, self::eval_atts( [ 'qty' => '0', 'mo-qty' => '5' ] ) );
		// cart qty 0 → 不加
		self::assertSame( 0.0, self::eval_atts( [ 'qty' => '10', 'mo-qty' => '0' ] ) );
	}

	public function test_weight_multiplies_by_cart_weight(): void {
		self::assertSame( 60.0, self::eval_atts( [ 'weight' => '20', 'mo-weight' => '3' ] ) );
		// 小數重量：20 × 1.5 = 30
		self::assertSame( 30.0, self::eval_atts( [ 'weight' => '20', 'mo-weight' => '1.5' ] ) );
	}

	public function test_combined_temp_n_plus_weight(): void {
		// per-temp base + 每公斤
		$atts = [ 'temp_3' => '200', 'weight' => '20', 'mo-weight' => '2' ];
		self::assertSame( 240.0, self::eval_for_temps( [ 3 ], $atts ) );
	}

	public function test_legacy_pattern_base_plus_cool_2_cool_3_still_works(): void {
		// 模擬 `100 + [mo_addfee cool_2="50" cool_3="100"]` 的 shortcode 部分
		// （`100 +` 是 formula 層的事，shortcode 只回 cool_2 + cool_3 部分）
		$atts = [ 'cool_2' => '50', 'cool_3' => '100' ];
		self::assertSame( 0.0, self::eval_for_temps( [ 1 ], $atts ) );
		self::assertSame( 50.0, self::eval_for_temps( [ 2 ], $atts ) );
		self::assertSame( 100.0, self::eval_for_temps( [ 3 ], $atts ) );
		self::assertSame( 150.0, self::eval_for_temps( [ 1, 2, 3 ], $atts ) );
	}

	public function test_zero_or_negative_string_value_does_not_add(): void {
		self::assertSame( 0.0, self::eval_for_temps( [ 1 ], [ 'temp_1' => '0' ] ) );
		// 負值不加（程式判 > 0 才加）
		self::assertSame( 0.0, self::eval_for_temps( [ 1 ], [ 'temp_1' => '-50' ] ) );
	}

	public function test_no_mo_temps_attribute_treats_as_no_temps(): void {
		// mo-temps 未注入 → temps array 是空 → 所有 temp_N / cool* 都不 fire
		self::assertSame( 0.0, self::eval_atts( [ 'temp_1' => '100', 'cool' => '60', 'cool_3' => '100' ] ) );
	}

	public function test_invalid_mo_temps_string_filtered_out(): void {
		// 含非數字 token → array_filter(intval) 過濾掉 0 → 剩 1
		self::assertSame( 100.0, self::eval_atts( [ 'mo-temps' => 'foo,1,bar,', 'temp_1' => '100', 'temp_2' => '150' ] ) );
	}
}
