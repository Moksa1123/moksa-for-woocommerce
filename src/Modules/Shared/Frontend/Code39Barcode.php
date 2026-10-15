<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * 超商條碼繳費用的 Code39 條碼。
 *
 * 用表格儲存格的底色畫線條，不用圖片、SVG 或字型：多數郵件軟體會擋 data: 圖片與 SVG，
 * 也載不到網頁字型，表格底色在頁面和信件裡都畫得出來。
 */
final class Code39Barcode {

	/** 每個字元 5 條 4 空，1 = 粗、0 = 細，從條開始交替。 */
	private const PATTERNS = [
		'0' => '000110100',
		'1' => '100100001',
		'2' => '001100001',
		'3' => '101100000',
		'4' => '000110001',
		'5' => '100110000',
		'6' => '001110000',
		'7' => '000100101',
		'8' => '100100100',
		'9' => '001100100',
		'A' => '100001001',
		'B' => '001001001',
		'C' => '101001000',
		'D' => '000011001',
		'E' => '100011000',
		'F' => '001011000',
		'G' => '000001101',
		'H' => '100001100',
		'I' => '001001100',
		'J' => '000011100',
		'K' => '100000011',
		'L' => '001000011',
		'M' => '101000010',
		'N' => '000010011',
		'O' => '100010010',
		'P' => '001010010',
		'Q' => '000000111',
		'R' => '100000110',
		'S' => '001000110',
		'T' => '000010110',
		'U' => '110000001',
		'V' => '011000001',
		'W' => '111000000',
		'X' => '010010001',
		'Y' => '110010000',
		'Z' => '011010000',
		'-' => '010000101',
		'.' => '110000100',
		' ' => '011000100',
		'$' => '010101000',
		'/' => '010100010',
		'+' => '010001010',
		'%' => '000101010',
		'*' => '010010100',
	];

	public static function html( string $value, int $narrow = 1, int $height = 50 ): string {
		$value = strtoupper( trim( $value ) );
		if ( '' === $value || str_contains( $value, '*' ) ) {
			return '';
		}
		$wide  = $narrow * 3;
		$cells = '';
		$total = 0;
		foreach ( str_split( '*' . $value . '*' ) as $i => $char ) {
			if ( ! isset( self::PATTERNS[ $char ] ) ) {
				return '';
			}
			if ( $i > 0 ) {
				$cells .= self::cell( $narrow, $height, false );
				$total += $narrow;
			}
			foreach ( str_split( self::PATTERNS[ $char ] ) as $j => $bit ) {
				$cells .= self::cell( '1' === $bit ? $wide : $narrow, $height, 0 === $j % 2 );
				$total += '1' === $bit ? $wide : $narrow;
			}
		}
		// 左右留白是掃描器辨識起訖的安靜區，不能省。表格寫死總寬並固定欄寬，
		// 不然瀏覽器會重新分配欄寬，細線被壓成 1px 就掃不出來。
		return '<div style="overflow-x:auto;background:#ffffff;padding:8px 20px;margin:.25em 0 .75em;">'
			. '<table role="presentation" width="' . $total . '" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;table-layout:fixed;width:' . $total . 'px;"><tr>'
			. $cells
			. '</tr></table></div>';
	}

	private static function cell( int $width, int $height, bool $bar ): string {
		$color = $bar ? '#000000' : '#ffffff';
		return sprintf(
			'<td width="%1$d" height="%2$d" bgcolor="%3$s" style="width:%1$dpx;min-width:%1$dpx;height:%2$dpx;background:%3$s;padding:0;font-size:0;line-height:0;"></td>',
			$width,
			$height,
			$color
		);
	}
}
