<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

use Moksafowo\Modules\Shipping\Tracking\TrackingLink;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單的貨態追蹤連結(唯讀)。聚合 ECPay / PAYUNi / SmilePay 三家的物流 records,
 * 各自丟給 Shipping\Tracking\TrackingLink::for_*_record 解析出 { carrier, tracking_no, url, mode }。
 */
final class TrackingLookup {

	/**
	 * @param \WC_Order $order 訂單。
	 * @return array<int, array{carrier:string, tracking_no:string, url:string, mode:string}>
	 */
	public static function for_order( \WC_Order $order ): array {
		$links = array();

		$sources = array(
			array( Keys::ECPAY_LOGISTIC_RECORDS, 'for_ecpay_record' ),
			array( Keys::PAYUNI_SHIPPING_RECORDS, 'for_payuni_record' ),
			array( Keys::SMILEPAY_SHIPPING_RECORDS, 'for_smilepay_record' ),
		);
		foreach ( $sources as $s ) {
			$records = $order->get_meta( $s[0] );
			if ( ! is_array( $records ) ) {
				continue;
			}
			foreach ( $records as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$info = call_user_func( array( TrackingLink::class, $s[1] ), $r );
				if ( is_array( $info ) && ! empty( $info['url'] ) ) {
					$links[] = array(
						'carrier'     => (string) ( $info['carrier'] ?? '' ),
						'tracking_no' => (string) ( $info['tracking_no'] ?? '' ),
						'url'         => (string) $info['url'],
						'mode'        => (string) ( $info['mode'] ?? '' ),
					);
				}
			}
		}
		return $links;
	}

	/**
	 * @param mixed $input { order: string }。
	 * @return array<string,mixed>
	 */
	public static function execute( $input ): array {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return array( 'links' => array() );
		}
		$ref   = is_array( $input ) && isset( $input['order'] ) ? (string) $input['order'] : '';
		$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return array(
				'links'   => array(),
				'message' => __( '找不到訂單。', 'moksa-for-woocommerce' ),
			);
		}
		return array(
			'order' => (string) $order->get_order_number(),
			'links' => self::for_order( $order ),
		);
	}
}
