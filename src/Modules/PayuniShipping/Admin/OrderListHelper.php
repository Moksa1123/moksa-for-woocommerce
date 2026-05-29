<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Admin;

use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\SevenEleven\B2CUnified;
use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\SevenEleven\C2CUnified;
use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\TCat\HDUnified;
use MoksaWeb\Mowc\Order\Meta\Keys;

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
		// 只處理 PAYUNi unified methods（HD / 7-11 C2C / 7-11 B2C）
		if ( ! in_array( $method_id, [ HDUnified::ID, C2CUnified::ID, B2CUnified::ID ], true ) ) {
			return $address;
		}
		$is_cvs = in_array( $method_id, [ C2CUnified::ID, B2CUnified::ID ], true );

		// 拿運送方式中文標題
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
		if ( '' !== $name ) {
			$lines[] = esc_html( $name );
		}
		if ( '' !== $method_title ) {
			$lines[] = esc_html( $method_title );
		}

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
			// HOME 黑貓 — 用實體運送地址。縣市英文代碼 → 中文 + 鄉鎮市區。
			$formatted = \MoksaWeb\Mowc\Modules\Address\TwAddress::format_shipping_address( $order );
			if ( '' !== $formatted ) {
				$lines[] = esc_html( $formatted );
			}
		}
		return implode( '<br/>', $lines );
	}
}
