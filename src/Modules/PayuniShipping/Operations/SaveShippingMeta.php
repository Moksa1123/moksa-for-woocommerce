<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Operations;

use Automattic\Jetpack\Constants;
use Moksafowo\Modules\PayuniShipping\Api\ShippingRequest;
use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Providers\TCat\HDFrozen;
use Moksafowo\Modules\PayuniShipping\Providers\TCat\HDNormal;
use Moksafowo\Modules\PayuniShipping\Providers\TCat\HDRefrigerated;
use Moksafowo\Modules\PayuniShipping\Utils\GoodsType;
use Moksafowo\Modules\PayuniShipping\Utils\LgsType;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;
use Moksafowo\Modules\PayuniShipping\Utils\ServiceType;
use Moksafowo\Modules\PayuniShipping\Utils\ShipType;

defined( 'ABSPATH' ) || exit;

final class SaveShippingMeta {

	public static function save_ship_trade_no( $order, $data ): void {
		if ( empty( $data['ShipTradeNo'] ) || ! $order->needs_shipping_address() ) {
			return;
		}
		$order->update_meta_data( OrderMeta::ShipTradeNo, $data['ShipTradeNo'] );
		$order->save();
		/* translators: %s: PAYUNi ship trade no */
		$order->add_order_note( sprintf( __( 'PAYUNi 物流單建立成功（單號 %s）', 'mo-ectools' ), $data['ShipTradeNo'] ) );
	}

	// CVS 路徑已由 StoreSelector::save_store_selection 處理；此方法服務黑貓宅配及後台編輯
	public static function save_hd_shipping_meta( $order, $data ): void {
		PayuniShipping::log( 'save_hd_shipping_meta data:' . wc_print_r( $data, true ) );

		if ( ! ( $order->has_shipping_method( HDNormal::ID ) || $order->has_shipping_method( HDFrozen::ID ) || $order->has_shipping_method( HDRefrigerated::ID ) ) ) {
			return;
		}

		$order->update_meta_data( OrderMeta::LgsType, LgsType::HOME );
		$order->update_meta_data( OrderMeta::ShipType, ShipType::TCAT );

		$shipping_method_id = '';
		foreach ( $order->get_shipping_methods() as $method ) {
			$shipping_method_id = $method->get_method_id();
			break;
		}

		// PackageSpec 不覆寫已設值，保留 admin 手動調整
		foreach ( \WC_Shipping_Zones::get_zones() as $shipping_zone ) {
			foreach ( $shipping_zone['shipping_methods'] as $shipping_method ) {
				if ( $shipping_method->id !== $shipping_method_id ) {
					continue;
				}
				if ( empty( $order->get_meta( OrderMeta::PackageSpec ) ) ) {
					$order->update_meta_data( OrderMeta::PackageSpec, $shipping_method->get_option( 'package_spec' ) );
				}
				break 2;
			}
		}

		if ( $order->has_shipping_method( HDNormal::ID ) ) {
			$order->update_meta_data( OrderMeta::GoodsType, GoodsType::NORMAL );
		}
		if ( $order->has_shipping_method( HDFrozen::ID ) ) {
			$order->update_meta_data( OrderMeta::GoodsType, GoodsType::FROZEN );
		}
		if ( $order->has_shipping_method( HDRefrigerated::ID ) ) {
			$order->update_meta_data( OrderMeta::GoodsType, GoodsType::REFRIGERATED );
		}

		// WC < 5.6 沒 get_shipping_phone()，舊版仍走 _shipping_phone meta
		if ( isset( $data['shipping_phone'] ) && version_compare( Constants::get_constant( 'WC_VERSION' ), '5.6.0', '<' ) ) {
			$order->update_meta_data( '_shipping_phone', $data['shipping_phone'] );
		}

		$is_cod = 'cod' === $order->get_payment_method();
		$order->update_meta_data( OrderMeta::ServiceType, $is_cod ? ServiceType::COD : ServiceType::NOT_COD );
		$order->update_meta_data( OrderMeta::TradeAmt, ShippingRequest::get_trade_amt( $order ) );

		$order->save();
	}

	public static function on_saved_order_items( $order_id, $items ): void {
		if ( ! isset( $items['shipping_method'] ) ) {
			return;
		}
		foreach ( $items['shipping_method'] as $item_id => $shipping_method ) {
			if ( PayuniShipping::is_moksafowo_payuni_shipping_hd( $shipping_method ) ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					self::save_hd_shipping_meta( $order, [] );
				}
			}
		}
	}

	public static function get_order_total_weight( $order ): float {
		$total = 0.0;
		foreach ( $order->get_items() as $product_item ) {
			$qty     = (int) $product_item->get_quantity();
			$product = $product_item->get_product();
			if ( $product ) {
				$total += (float) $product->get_weight() * $qty;
			}
		}
		return $total;
	}
}
