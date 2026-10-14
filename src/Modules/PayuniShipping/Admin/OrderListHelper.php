<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Admin;

use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Providers\TCat\HDUnified;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class OrderListHelper {

	public static function init(): void {
		add_filter( 'woocommerce_order_get_formatted_shipping_address', [ __CLASS__, 'inject_address' ], 10, 3 );
	}

	public static function inject_address( string $address, $raw_address, \WC_Order $order ): string {
		$method_id = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$method_id = (string) $m->get_method_id();
			break;
		}
		// 依溫層分開的 7-11 方式（例 711_c2c_normal）也是超商，不能只認合併版的 ID。
		$is_cvs = PayuniShipping::needs_cvs( $method_id );
		if ( ! $is_cvs && HDUnified::ID !== $method_id ) {
			return $address;
		}

		$method_title = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid          = (string) $m->get_method_id();
			$name         = (string) $m->get_name();
			$method_title = ( '' !== $name && $name !== $mid ) ? $name : $mid;
			break;
		}

		$name = trim( $order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() );
		}

		$lines = [];

		if ( $is_cvs ) {
			$store_id   = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ID );
			$store_name = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_NAME );
			$store_addr = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ADDRESS );
			if ( '' === $store_id ) {
				return $address;
			}
			$lines[] = esc_html( $store_name ) . ' (' . esc_html( $store_id ) . ')';
			if ( '' !== $store_addr ) {
				$lines[] = esc_html( $store_addr );
			}
		} else {
			foreach ( \Moksafowo\Modules\Address\TwAddress::shipping_address_lines( $order ) as $line ) {
				$lines[] = esc_html( $line );
			}
		}
		if ( '' !== $name ) {
			$lines[] = esc_html( $name );
		}
		if ( '' !== $method_title ) {
			$lines[] = esc_html( $method_title );
		}
		return implode( '<br/>', $lines );
	}
}
