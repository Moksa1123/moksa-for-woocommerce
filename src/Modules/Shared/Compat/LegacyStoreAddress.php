<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * 舊外掛（RY、綠界官方）的超商訂單，收件地址改顯示取貨門市，跟 RY 啟用時看到的一樣。
 * RY 還在啟用時由它自己處理，這裡不重複。
 */
final class LegacyStoreAddress {

	public static function init(): void {
		add_filter( 'woocommerce_order_get_formatted_shipping_address', [ __CLASS__, 'filter' ], 20, 3 );
	}

	/**
	 * @param string    $address 格式化好的收件地址。
	 * @param mixed     $raw     原始地址欄位。
	 * @param \WC_Order $order   訂單。
	 */
	public static function filter( $address, $raw, $order ) {
		if ( defined( 'RY_WT_VERSION' ) || ! $order instanceof \WC_Order ) {
			return $address;
		}
		$method_title = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			if ( str_starts_with( (string) $m->get_method_id(), 'moksafowo_' ) ) {
				return $address;
			}
			$method_title = (string) $m->get_name();
			break;
		}
		$store = LegacyOrderData::store( $order );
		if ( null === $store ) {
			return $address;
		}

		$lines = [ esc_html( trim( $store['name'] . ' (' . $store['id'] . ')' ) ) ];
		if ( '' !== $store['address'] ) {
			$lines[] = esc_html( $store['address'] );
		}
		$name = trim( $order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() );
		}
		if ( '' !== $name ) {
			$lines[] = esc_html( $name );
		}
		if ( '' !== $method_title ) {
			$lines[] = esc_html( $method_title );
		}
		// HPOS 列表會對這個值跑 esc_html，所以只用 <br/> 分行，WC 會把它換成逗號。
		return implode( '<br/>', $lines );
	}
}
