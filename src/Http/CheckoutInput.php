<?php

declare( strict_types=1 );

namespace Moksafowo\Http;

defined( 'ABSPATH' ) || exit;

/**
 * 讀結帳送出的欄位（付款 prime、paySession、發票、行政區）。
 *
 * 不能用 Input（filter_input）：區塊結帳走 Store API，WC 會把 payment_data 塞進 $_POST
 * 再呼叫傳統金流的 process_payment，原始請求裡沒有這些欄位。
 *
 * 每次讀之前都在這裡重新驗一次這個流程自己的 nonce，驗不過就當作沒送（fail closed）：
 * - 傳統結帳：woocommerce-process-checkout-nonce（woocommerce-process_checkout）
 * - 訂單付款頁：woocommerce-pay-nonce（woocommerce-pay）
 * - 訂閱換付款方式：_wcsnonce（wcs_change_payment_method）
 * - Store API：WC 的路由已驗過標頭 Nonce（wc_store_api），這裡確認是 Store API 請求即可
 *
 * 回傳值已逐層 sanitize_text_field；需要更嚴格的（absint、sanitize_key）由呼叫端再套。
 */
final class CheckoutInput {

	/** [ 欄位, nonce 動作 ]。WC 傳統結帳也接受 _wpnonce（部分第三方結帳外掛送這個）。 */
	private const FORM_NONCES = [
		[ 'woocommerce-process-checkout-nonce', 'woocommerce-process_checkout' ],
		[ '_wpnonce', 'woocommerce-process_checkout' ],
		[ 'woocommerce-pay-nonce', 'woocommerce-pay' ],
		[ '_wcsnonce', 'wcs_change_payment_method' ],
	];

	/**
	 * 這個請求已經驗過 nonce。
	 *
	 * 傳統結帳勾「建立帳號」（或買訂閱商品時 WC 強制註冊）時，WC 會在結帳途中建立帳號並登入，
	 * 目前使用者換人，以訪客身分產生的 nonce 之後再驗就會失敗——付款 prime、發票欄位因此讀不到。
	 * 所以 WC 一開始處理結帳（woocommerce_checkout_process，帳號還沒建立）就先驗一次（prime()），
	 * 驗過就記下來，同一個請求後面的讀取沿用。
	 *
	 * @var bool
	 */
	private static bool $verified = false;

	/** 掛在 woocommerce_checkout_process：趁帳號還沒建立前驗 nonce 並記住結果。 */
	public static function prime(): void {
		self::read( '' );
	}

	/** 欄位值（已 unslash、sanitize_text_field）；nonce 不過、不存在或不是字串時回空字串。 */
	public static function post( string $key ): string {
		$value = self::read( $key );
		return is_string( $value ) ? $value : '';
	}

	/** 欄位有沒有送（含空字串）；nonce 不過一律 false。 */
	public static function has( string $key ): bool {
		return null !== self::read( $key );
	}

	/**
	 * 陣列欄位（例如 Store API 的 additional_fields）；nonce 不過或不是陣列時回空陣列。
	 *
	 * @return array<string|int,mixed>
	 */
	public static function post_array( string $key ): array {
		$value = self::read( $key );
		return is_array( $value ) ? $value : [];
	}

	/** @return mixed */
	private static function read( string $key ) {
		$verified = self::$verified;
		if ( ! $verified ) {
			foreach ( self::FORM_NONCES as [ $field, $action ] ) {
				$nonce = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
				if ( '' !== $nonce && false !== wp_verify_nonce( $nonce, $action ) ) {
					$verified = true;
					break;
				}
			}
			// Store API 的結帳路由在呼叫付款之前，已照 WC 自己的規則驗過標頭 Nonce（wc_store_api），
			// 驗不過根本到不了這裡；這裡只確認真的是 Store API 請求。
			if ( ! $verified && self::is_store_api_request() ) {
				$verified = true;
			}
			self::$verified = $verified;
		}
		if ( ! $verified || '' === $key || ! isset( $_POST[ $key ] ) ) {
			return null;
		}
		return map_deep( wp_unslash( $_POST[ $key ] ), 'sanitize_text_field' );
	}

	private static function is_store_api_request(): bool {
		return StoreApi::is_request();
	}

	private function __construct() {}
}
