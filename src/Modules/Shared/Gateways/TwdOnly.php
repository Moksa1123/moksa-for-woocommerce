<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * 台灣金流只收新台幣。商店或訂單幣別不是 TWD 時，金額數字會被當成新台幣送出，
 * 付款通知也照樣比對成功，所以直接不顯示。
 */
final class TwdOnly {

	public static function ok(): bool {
		$currency = get_woocommerce_currency();
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			if ( $order instanceof \WC_Order ) {
				$currency = $order->get_currency();
			}
		}
		return 'TWD' === strtoupper( (string) $currency );
	}

	private function __construct() {}
}
