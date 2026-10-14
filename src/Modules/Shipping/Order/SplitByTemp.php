<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Order;

use Moksafowo\Modules\Shipping\Methods\AbstractShippingMethod;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class SplitByTemp {

	public static function for_order( \WC_Order $order, array $supported_temps, ?AbstractShippingMethod $method = null ): array {
		$split_shipping_fee = $method instanceof AbstractShippingMethod && $method->split_shipping_fee_enabled();
		$groups             = ProductTemp::group_order_items( $order, $supported_temps );
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

		// split_shipping_fee：per-temp eval 與 calculate_shipping 邏輯一致，sum = get_shipping_total()
		if ( $split_shipping_fee && $method instanceof AbstractShippingMethod ) {
			foreach ( $packages as $i => &$p ) {
				$fee               = (int) round( $method->evaluate_cost_for_temp( (int) $p['temp'], (int) $p['qty'], (float) $p['weight'] ) );
				$p['shipping_fee'] = $fee;
				$p['amount']      += $fee;
			}
			unset( $p );
			return self::reconcile_with_order_total( $order, $packages );
		}

		// 整單運費塞給第一包（COD 第一張代收）
		$shipping_fee = (int) round( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax() );
		if ( $shipping_fee > 0 ) {
			$packages[0]['amount']      += $shipping_fee;
			$packages[0]['shipping_fee'] = $shipping_fee;
		}
		return self::reconcile_with_order_total( $order, $packages );
	}

	/**
	 * 各包金額加總要等於顧客實際應付（訂單總額扣掉已退款）。只加商品與運費會漏掉
	 * 費用項目（貨到付款手續費、負數折抵）、逐行四捨五入的誤差，以及出貨前的部分退款；
	 * 取貨付款時門市就會少收或多收。差額併進第一包（已含整單運費、代收主單）。
	 *
	 * @param array<int,array<string,mixed>> $packages 各溫層包裹。
	 * @return array<int,array<string,mixed>>
	 */
	private static function reconcile_with_order_total( \WC_Order $order, array $packages ): array {
		$target = (int) round( (float) $order->get_total() - (float) $order->get_total_refunded() );
		$sum    = (int) array_sum( array_column( $packages, 'amount' ) );
		$diff   = $target - $sum;
		if ( 0 !== $diff && $target > 0 ) {
			$packages[0]['amount'] = max( 0, (int) $packages[0]['amount'] + $diff );
		}
		return $packages;
	}
}
