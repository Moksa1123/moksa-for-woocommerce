<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Tests\Unit;

use MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTempField;
use PHPUnit\Framework\TestCase;

/**
 * ProductTempField CSV import 邏輯 — parse_value + normalize_csv_temp + mapping_defaults
 * 不碰 WC_Product，純函數路徑能直接測。
 */
final class ProductTempFieldCsvTest extends TestCase {

	private static function parse( string $raw ): string {
		$data = ProductTempField::csv_import_parse_value( [ 'moksafowo_product_temp' => $raw ], null );
		return (string) ( $data['moksafowo_product_temp'] ?? '' );
	}

	public function test_numeric_inputs_pass_through(): void {
		self::assertSame( '1', self::parse( '1' ) );
		self::assertSame( '2', self::parse( '2' ) );
		self::assertSame( '3', self::parse( '3' ) );
	}

	public function test_chinese_labels_map_to_numbers(): void {
		self::assertSame( '1', self::parse( '常溫' ) );
		self::assertSame( '2', self::parse( '冷藏' ) );
		self::assertSame( '3', self::parse( '冷凍' ) );
	}

	public function test_english_labels_case_insensitive(): void {
		self::assertSame( '1', self::parse( 'normal' ) );
		self::assertSame( '1', self::parse( 'Normal' ) );
		self::assertSame( '1', self::parse( 'NORMAL' ) );
		self::assertSame( '2', self::parse( 'refrigerated' ) );
		self::assertSame( '2', self::parse( 'Refrigerated' ) );
		self::assertSame( '2', self::parse( 'REFRIGERATED' ) );
		self::assertSame( '3', self::parse( 'frozen' ) );
		self::assertSame( '3', self::parse( 'FROZEN' ) );
	}

	public function test_clear_markers_return_clear_sentinel(): void {
		self::assertSame( '__clear__', self::parse( '-' ) );
		self::assertSame( '__clear__', self::parse( 'unset' ) );
		self::assertSame( '__clear__', self::parse( 'UNSET' ) );
		self::assertSame( '__clear__', self::parse( 'Clear' ) );
		self::assertSame( '__clear__', self::parse( '(none)' ) );
		self::assertSame( '__clear__', self::parse( 'None' ) );
		self::assertSame( '__clear__', self::parse( '預設' ) );
		self::assertSame( '__clear__', self::parse( 'default' ) );
	}

	public function test_invalid_inputs_return_empty(): void {
		self::assertSame( '', self::parse( '亂打字' ) );
		self::assertSame( '', self::parse( '0' ) );      // 0 不是有效溫層
		self::assertSame( '', self::parse( '4' ) );      // 超出範圍
		self::assertSame( '', self::parse( '' ) );
		self::assertSame( '', self::parse( '   ' ) );     // 純空白 trim 後空字串
	}

	public function test_whitespace_trimmed(): void {
		self::assertSame( '1', self::parse( '  1  ' ) );
		self::assertSame( '2', self::parse( ' 冷藏 ' ) );
		self::assertSame( '__clear__', self::parse( '  unset  ' ) );
	}

	public function test_parse_value_passthrough_when_key_missing(): void {
		$data = ProductTempField::csv_import_parse_value( [ 'other_key' => 'foo' ], null );
		self::assertArrayNotHasKey( 'moksafowo_product_temp', $data );
		self::assertSame( 'foo', $data['other_key'] );
	}

	public function test_mapping_defaults_includes_case_variants(): void {
		$cols = ProductTempField::csv_import_mapping_defaults( [] );

		// 英文 base + 大小寫變體
		self::assertArrayHasKey( 'moksafowo_product_temp', $cols );
		self::assertArrayHasKey( 'MOKSAFOWO_PRODUCT_TEMP', $cols );
		self::assertArrayHasKey( 'Moksafowo_Product_Temp', $cols );
		self::assertArrayHasKey( 'moksafowo product temp', $cols );
		self::assertArrayHasKey( 'MOKSAFOWO PRODUCT TEMP', $cols );
		self::assertArrayHasKey( 'Moksafowo Product Temp', $cols );

		// 中文 header
		self::assertArrayHasKey( '物流溫層', $cols );
		self::assertArrayHasKey( '溫層', $cols );

		// 全部應 map 到 mo_product_temp
		foreach ( $cols as $val ) {
			self::assertSame( 'moksafowo_product_temp', $val );
		}
	}

	public function test_mapping_defaults_preserves_other_columns(): void {
		$cols = ProductTempField::csv_import_mapping_defaults( [ 'existing' => 'other_field' ] );
		self::assertSame( 'other_field', $cols['existing'] );
		self::assertArrayHasKey( 'moksafowo_product_temp', $cols );
	}
}
