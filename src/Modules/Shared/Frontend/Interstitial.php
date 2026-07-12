<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * 獨立過場頁（auto-submit form / 等待頁）共用輸出器。
 *
 * 這些頁面在 admin-post / wc-api context 輸出、不掛佈景主題，無法走一般
 * enqueue 管線 — 樣式用 style 屬性逐元素帶（無 <style> 區塊），JS 一律走
 * wp_print_inline_script_tag()（WP 6.3+ 官方 inline script 輸出 API）。
 */
final class Interstitial {

	private const BODY_STYLE = 'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;padding:32px;text-align:center;color:#374151;background:#f0f0f0;margin:0;';
	private const CARD_STYLE = 'max-width:480px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.1);';
	private const H_STYLE    = 'margin:0 0 12px;color:#333;';
	private const P_STYLE    = 'margin:4px 0;color:#6b7280;';

	/**
	 * 輸出整頁過場 HTML 並結束請求前的所有輸出（呼叫端自行 exit）。
	 *
	 * @param string   $title     <title> 文字。
	 * @param string   $heading   卡片標題。
	 * @param string[] $paragraphs 說明段落（純文字，逐行輸出）。
	 * @param string   $forms_html 已組好的 <form>（含 hidden inputs）HTML；經 wp_kses 過濾。
	 * @param string   $script     要執行的 inline JS（auto submit 等）。
	 */
	public static function render( string $title, string $heading, array $paragraphs, string $forms_html, string $script ): void {
		echo '<!DOCTYPE html><html ' . get_language_attributes( 'html' ) . '><head>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() is core-escaped.
		echo '<meta charset="' . esc_attr( get_bloginfo( 'charset', 'display' ) ) . '">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '</head><body style="' . esc_attr( self::BODY_STYLE ) . '">';
		echo '<div style="' . esc_attr( self::CARD_STYLE ) . '">';
		echo '<h2 style="' . esc_attr( self::H_STYLE ) . '">' . esc_html( $heading ) . '</h2>';
		foreach ( $paragraphs as $p ) {
			echo '<p style="' . esc_attr( self::P_STYLE ) . '">' . wp_kses(
				$p,
				[
					'strong' => [],
					'br'     => [],
				]
			) . '</p>';
		}
		echo '</div>';
		echo wp_kses( $forms_html, self::form_allowlist() );
		if ( '' !== $script ) {
			wp_print_inline_script_tag( $script );
		}
		echo '</body></html>';
	}

	/**
	 * 過場頁 form 專用 kses allowlist。
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function form_allowlist(): array {
		return [
			'form'  => [
				'method'         => true,
				'id'             => true,
				'action'         => true,
				'target'         => true,
				'accept-charset' => true,
			],
			'input' => [
				'type'  => true,
				'name'  => true,
				'value' => true,
				'id'    => true,
			],
		];
	}

	/**
	 * 物流標籤列印頁 kses allowlist — 物流商 print server 回傳的標籤 HTML
	 * （表格 / 圖片 / 樣式）允許，active content（script / on* / iframe）剔除。
	 * 各物流 PrintProxy 共用，避免重複定義。
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function label_allowlist(): array {
		$attrs  = [
			'id'          => true,
			'class'       => true,
			'style'       => true,
			'align'       => true,
			'valign'      => true,
			'width'       => true,
			'height'      => true,
			'border'      => true,
			'cellpadding' => true,
			'cellspacing' => true,
			'colspan'     => true,
			'rowspan'     => true,
			'bgcolor'     => true,
		];
		$tags   = [ 'html', 'head', 'body', 'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'ul', 'ol', 'li', 'b', 'strong', 'i', 'em', 'u', 'br', 'hr', 'center', 'font', 'section', 'header', 'footer' ];
		$result = [];
		foreach ( $tags as $tag ) {
			$result[ $tag ] = $attrs;
		}
		$result['meta']  = [
			'charset'    => true,
			'name'       => true,
			'content'    => true,
			'http-equiv' => true,
		];
		$result['title'] = [];
		$result['style'] = [
			'type'  => true,
			'media' => true,
		];
		$result['link']  = [
			'rel'   => true,
			'href'  => true,
			'type'  => true,
			'media' => true,
		];
		$result['img']   = array_merge(
			$attrs,
			[
				'src' => true,
				'alt' => true,
			]
		);
		return $result;
	}
}
