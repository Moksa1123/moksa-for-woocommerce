<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\NewebpayShipping\Operations;

use Moksafowo\Modules\NewebpayShipping\Api\ShippingRequest;
use Moksafowo\Order\Meta\Keys;

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
			// NPA-B54 的 MerchantOrderNo 是建單時送的商店訂單編號，不是物流單號。
			$mtn       = (string) $o->get_meta( Keys::NEWEBPAY_SHIPPING_MERCHANT_ORDER_NO );
			$lgs_type  = (string) $o->get_meta( Keys::NEWEBPAY_SHIPPING_LGS_TYPE ) ?: 'C2C';
			$ship_type = (string) $o->get_meta( Keys::NEWEBPAY_SHIPPING_SHIP_TYPE ) ?: '1'; // default 7-11
			if ( '' === $mtn ) {
				continue;
			}
			$key               = $lgs_type . '-' . $ship_type;
			$buckets[ $key ][] = $mtn;
		}

		$forms = [];
		foreach ( $buckets as $key => $mtns ) {
			[ $lgs_type, $ship_type ] = explode( '-', $key, 2 );
			$limit                    = self::print_limit( $lgs_type, $ship_type );
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
		$mtn = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_MERCHANT_ORDER_NO );
		return '' !== $mtn ? 1 : 0;
	}

	private static function print_limit( string $lgs_type, string $ship_type ): int {
		// 錯誤碼 2106：全家一次 8 張，7-11／萊爾富／OK 18 張
		return '2' === $ship_type ? 8 : 18;
	}
}
