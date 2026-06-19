<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Admin;

use MoksaWeb\Mowc\Modules\PayuniShipping\Api\ShippingRequest;
use MoksaWeb\Mowc\Modules\PayuniShipping\PayuniShipping;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\SingletonTrait;
use MoksaWeb\Mowc\Modules\Shared\Admin\OrderInfoLayout;


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
		if ( empty( $theorder ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only order context resolution; WC core verifies its own nonce before persisting.
			if ( isset( $_POST['post_ID'] ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.NonceVerification.Missing -- WC core metabox callback injects $theorder; read-only context resolution, WC core save flow verifies its own nonce.
				$theorder = wc_get_order( absint( wp_unslash( $_POST['post_ID'] ) ) );
			} else {
				return $shipping_fields;
			}
		}

		$shipping_method = false;

		$items_shipping = $theorder->get_items( 'shipping' );
		$items_shipping = array_shift( $items_shipping );

		if ( $items_shipping ) {
			$shipping_method = PayuniShipping::is_moksafowo_payuni_shipping_cvs( $items_shipping->get_method_id() );
		}

		if ( false !== $shipping_method ) {

			$shipping_fields['moksafowo_payuni_storeid']      = array(
				'label' => __( 'Store ID', 'mo-ectools' ),
				'show'  => false,
			);
			$shipping_fields['moksafowo_payuni_storename']    = array(
				'label' => __( 'Store Name', 'mo-ectools' ),
				'show'  => false,
			);
			$shipping_fields['moksafowo_payuni_storeaddress'] = array(
				'label' => __( 'Store Address', 'mo-ectools' ),
				'show'  => false,
			);

			$shipping_fields['phone'] = array(
				'label' => __( 'Shipping Phone', 'mo-ectools' ),
			);

		} elseif ( $items_shipping ) {
			if ( PayuniShipping::is_moksafowo_payuni_shipping_hd( $items_shipping->get_method_id() ) ) {
				$shipping_fields['phone'] = array(
					'label' => __( 'Shipping Phone', 'mo-ectools' ),
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
					$order_actions['create_moksafowo_payuni_shipping_order'] = __( 'Create PAYUNi Shipping Order', 'mo-ectools' );
				} else {
					$order_actions['create_moksafowo_payuni_shipping_order'] = __( 'Re-Create PAYUNi Shipping Order', 'mo-ectools' );
				}
			}
		}
		return $order_actions;
	}
}
