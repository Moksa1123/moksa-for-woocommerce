<?php

declare( strict_types=1 );

namespace Moksafowo\Http;

defined( 'ABSPATH' ) || exit;

/**
 * 讀「不可能帶 WP nonce」的請求參數：金流／物流的幕後通知、幕前導回、選店地圖回傳，
 * 以及後台只用來判斷畫面的唯讀 GET 參數。
 *
 * 用 filter_input 讀 PHP 收到的原始請求，不經過 WP 加過反斜線的 $_GET／$_POST，
 * 所以呼叫端不要再 wp_unslash（會把簽章、JSON 裡合法的反斜線吃掉）。
 * 這裡只負責「讀」；來源驗證（簽章 hash_equals、order key、一次性 token、IP 白名單）
 * 和清理仍由各呼叫端在使用前完成，回傳值一律視為不可信。
 *
 * 結帳流程不要用這支：區塊結帳會把付款資料塞進 $_POST，原始請求裡沒有，見 CheckoutInput。
 */
final class Input {

	/** GET 參數的原始字串；不存在或不是字串時回空字串。 */
	public static function get( string $key ): string {
		return self::scalar( filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW ) );
	}

	/** POST 參數的原始字串；不存在或不是字串時回空字串。 */
	public static function post( string $key ): string {
		return self::scalar( filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW ) );
	}

	/** 先看 POST 再看 GET，取代 $_REQUEST（不含 cookie）。 */
	public static function request( string $key ): string {
		return self::has_post( $key ) ? self::post( $key ) : self::get( $key );
	}

	public static function has_get( string $key ): bool {
		return filter_has_var( INPUT_GET, $key );
	}

	public static function has_post( string $key ): bool {
		return filter_has_var( INPUT_POST, $key );
	}

	/**
	 * 整包 POST（巢狀陣列保留結構），值都是原始字串。
	 *
	 * @return array<string,mixed>
	 */
	public static function all_post(): array {
		return self::all( INPUT_POST );
	}

	/**
	 * 整包 GET。
	 *
	 * @return array<string,mixed>
	 */
	public static function all_get(): array {
		return self::all( INPUT_GET );
	}

	/** 原始請求本文（JSON 通知用）。multipart 請求這裡會是空的，要用 all_post()。 */
	public static function body(): string {
		$raw = file_get_contents( 'php://input' );
		return is_string( $raw ) ? $raw : '';
	}

	/** @return array<string,mixed> */
	private static function all( int $type ): array {
		$data = filter_input_array( $type, FILTER_UNSAFE_RAW, false );
		if ( ! is_array( $data ) ) {
			return [];
		}
		// FILTER_UNSAFE_RAW 對陣列值不會自動展開，第三參數 false 讓缺的 key 不補 null；巢狀陣列原樣保留。
		return $data;
	}

	/** @param mixed $value */
	private static function scalar( $value ): string {
		return is_string( $value ) ? $value : '';
	}

	private function __construct() {}
}
