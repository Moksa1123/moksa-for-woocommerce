<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Order;

use MoksaWeb\Mowc\Modules\Shipping\Methods\AbstractShippingMethod;
use MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class SplitByTemp {

	public static function for_order( \WC_Order $order, array $supported_temps, ?AbstractShippingMethod $method = null ): array {
		$split_shipping_fee = $method instanceof AbstractShippingMethod && $method->split_shipping_fee_enabled();
		$groups = ProductTemp::group_order_items( $order, $supported_temps );
		if ( empty( $groups ) ) {
			return [];
		}

		$packages = [];
		foreach ( $groups as $temp => $items ) {
			$amount = 0;
			$names  = [];
			$qty    = 0;
			$weight = 0.0;
			foreach ( $items as $item ) {
				$line_total = (float) $item->get_total() + (float) $item->get_total_tax();
				$amount    += (int) round( $line_total );
				$names[]    = (string) $item->get_name();
				$item_qty   = (int) $item->get_quantity();
				$qty       += $item_qty;
				$product    = $item->get_product();
				if ( $product instanceof \WC_Product ) {
					$w = (float) ( $product->get_weight() ?: 0 );
					if ( $w > 0 ) {
						$weight += $w * $item_qty;
					}
				}
			}

			$packages[] = [
				'temp'         => (int) $temp,
				'items'        => $items,
				'amount'       => $amount,
				'goods_amount' => $amount,
				'goods_name'   => implode( '#', $names ),
				'qty'          => $qty,
				'weight'       => $weight,
				'shipping_fee' => 0,
			];
		}

		if ( empty( $packages ) ) {
			return $packages;
		}

		// 開啟「依溫層分別計算運費」：每包用該溫層的 qty / weight 對 cost formula 重評
		// 一次，作為該物流單的運費。顧客結帳看到的運費 = sum（calculate_shipping 那邊
		// 也是同一個 per-temp eval 邏輯），所以 sum 自然 = $order->get_shipping_total()。
		if ( $split_shipping_fee && $method instanceof AbstractShippingMethod ) {
			foreach ( $packages as $i => &$p ) {
				$fee                 = (int) round( $method->evaluate_cost_for_temp( (int) $p['temp'], (int) $p['qty'], (float) $p['weight'] ) );
				$p['shipping_fee']   = $fee;
				$p['amount']        += $fee;
			}
			unset( $p );
			return $packages;
		}

		// 預設（RY 行為）— 整單運費全部塞給第一包，COD 第一張代收所有運費。
		$shipping_fee = (int) round( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax() );
		if ( $shipping_fee > 0 ) {
			$packages[0]['amount']      += $shipping_fee;
			$packages[0]['shipping_fee'] = $shipping_fee;
		}
		return $packages;
	}
}
