<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\NewebpayShipping\Operations;

use MoksaWeb\Mowc\Modules\NewebpayShipping\Api\ShippingRequest;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class PrintLabel {

	public static function render( array $order_ids, array $options = [] ): array {
		// 依 ship_type 分桶（7-11 / 全家 / 萊爾富 / OK），每桶內再按 lgs_type 分（B2C / C2C）
		$buckets = [];  // key = "B2C-1" => mtn[]
		foreach ( $order_ids as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o instanceof \WC_Order ) {
				continue;
			}
			$mtn = (string) $o->get_meta( Keys::NEWEBPAY_SHIPPING_LGS_NO );
			if ( '' === $mtn ) {
				$mtn = (string) $o->get_meta( Keys::NEWEBPAY_SHIPPING_MERCHANT_ORDER_NO );
			}
			$lgs_type  = (string) $o->get_meta( Keys::NEWEBPAY_SHIPPING_LGS_TYPE ) ?: 'C2C';
			$ship_type = (string) $o->get_meta( Keys::NEWEBPAY_SHIPPING_SHIP_TYPE ) ?: '1'; // default 7-11
			if ( '' === $mtn ) {
				continue;
			}
			$key = $lgs_type . '-' . $ship_type;
			$buckets[ $key ][] = $mtn;
		}

		$forms = [];
		foreach ( $buckets as $key => $mtns ) {
			[ $lgs_type, $ship_type ] = explode( '-', $key, 2 );
			$limit = self::print_limit( $lgs_type, $ship_type );
			// 切 chunks 不超過 API 上限
			foreach ( array_chunk( array_unique( $mtns ), $limit ) as $chunk ) {
				$result = ShippingRequest::print_label( $chunk, $lgs_type, $ship_type );
				if ( $result['ok'] ) {
					$forms[] = [
						'api_url'   => $result['api_url'],
						'form_data' => $result['form_data'],
					];
				}
			}
		}
		return $forms;
	}

	public static function record_count( \WC_Order $order ): int {
		$mtn = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_LGS_NO );
		if ( '' === $mtn ) {
			$mtn = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_MERCHANT_ORDER_NO );
		}
		return '' !== $mtn ? 1 : 0;
	}

	private static function print_limit( string $lgs_type, string $ship_type ): int {
		// 7-11 B2C 18 / C2C 8 / 全家/OK/萊爾富 18
		if ( '1' === $ship_type && 'C2C' === $lgs_type ) {
			return 8;
		}
		return 18;
	}
}
