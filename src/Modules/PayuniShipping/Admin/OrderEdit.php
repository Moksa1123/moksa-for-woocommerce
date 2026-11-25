<?php

namespace Moksafowo\Modules\PayuniShipping\Admin;

use Moksafowo\Modules\PayuniShipping\Api\ShippingRequest;
use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;
use Moksafowo\Modules\PayuniShipping\Utils\SingletonTrait;
use Moksafowo\Modules\Shared\Admin\OrderInfoLayout;


defined( 'ABSPATH' ) || exit;

class OrderEdit {

	use SingletonTrait;

	public static function init() {

			self::get_instance();

			add_filter( 'woocommerce_admin_shipping_fields', array( self::get_instance(), 'moksafowo_payuni_shipping_cvs_fields' ), 10, 1 );

			add_filter( 'woocommerce_order_actions', array( self::get_instance(), 'moksafowo_payuni_order_actions' ) );

			add_filter( 'woocommerce_order_action_create_moksafowo_payuni_shipping_order', array( ShippingRequest::get_instance(), 'moksafowo_payuni_create_shipping' ) );

			OrderInfoLayout::boot();
			add_filter( 'moksafowo_order_info_cards', array( __NAMESPACE__ . '\\OrderMetaBox', 'add_card' ), 20, 2 );
	}

	public static function moksafowo_payuni_shipping_cvs_fields( $shipping_fields ) {
		global $theorder;
		$order = $theorder instanceof \WC_Order ? $theorder : null;
		if ( null === $order ) {
			// 儲存訂單時 WC 不設 $theorder：用 WC 訂單資料區塊自己的 nonce 驗過，才從表單取訂單編號。
			$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) || ! isset( $_POST['post_ID'] ) ) {
				return $shipping_fields;
			}
			$order = wc_get_order( absint( wp_unslash( $_POST['post_ID'] ) ) );
			if ( ! $order instanceof \WC_Order ) {
				return $shipping_fields;
			}
		}

		$shipping_method = false;

		$items_shipping = $order->get_items( 'shipping' );
		$items_shipping = array_shift( $items_shipping );

		if ( $items_shipping ) {
			$shipping_method = PayuniShipping::is_moksafowo_payuni_shipping_cvs( $items_shipping->get_method_id() );
		}

		if ( false !== $shipping_method ) {
			// 門市三欄由 Shipping\Admin\CvsStoreEditor 統一提供（四家共用，且真的讀寫得到 meta）。
			$shipping_fields['phone'] = array(
				'label' => __( 'Shipping Phone', 'moksa-for-woocommerce' ),
			);

		} elseif ( $items_shipping ) {
			if ( PayuniShipping::is_moksafowo_payuni_shipping_hd( $items_shipping->get_method_id() ) ) {
				$shipping_fields['phone'] = array(
					'label' => __( 'Shipping Phone', 'moksa-for-woocommerce' ),
				);
			}
		}

		return $shipping_fields;
	}

	public static function moksafowo_payuni_order_actions( $order_actions ) {
		global $theorder;

		foreach ( $theorder->get_items( 'shipping' ) as $item_id => $item ) {
			if ( PayuniShipping::is_payuni_shipping( $item->get_method_id() ) !== false ) {
				if ( empty( $theorder->get_meta( OrderMeta::ShipTradeNo ) ) ) {
					$order_actions['create_moksafowo_payuni_shipping_order'] = __( 'Create PAYUNi Shipping Order', 'moksa-for-woocommerce' );
				} else {
					$order_actions['create_moksafowo_payuni_shipping_order'] = __( 'Re-Create PAYUNi Shipping Order', 'moksa-for-woocommerce' );
				}
			}
		}
		return $order_actions;
	}
}
