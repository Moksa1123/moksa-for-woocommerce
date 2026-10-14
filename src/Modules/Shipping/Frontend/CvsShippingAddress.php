<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * 超商取貨訂單的「運送地址」改成顯示取貨門市，訂單頁、我的帳號、信件、後台一致。
 * 綠界與 PAYUNi 各自已有同樣格式的實作；這裡給其他物流模組共用。
 */
final class CvsShippingAddress {

	/**
	 * HPOS 訂單列表會對這個 filter 的結果再跑 esc_html()，所以只用 <br/> 分行、不放其他標籤。
	 */
	public static function render( \WC_Order $order, string $store_id, string $store_name, string $store_addr ): string {
		$lines   = [];
		$lines[] = esc_html( $store_name ) . ' (' . esc_html( $store_id ) . ')';
		if ( '' !== $store_addr ) {
			$lines[] = esc_html( $store_addr );
		}

		$name = trim( $order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() );
		}
		if ( '' !== $name ) {
			$lines[] = esc_html( $name );
		}

		foreach ( $order->get_shipping_methods() as $method ) {
			$title = (string) $method->get_name();
			if ( '' !== $title ) {
				$lines[] = esc_html( $title );
			}
			break;
		}

		return implode( '<br/>', $lines );
	}
}
