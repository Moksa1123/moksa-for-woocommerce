<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Admin;

use MoksaWeb\Mowc\Modules\PayuniShipping\Api\ShippingRequest;
use MoksaWeb\Mowc\Modules\PayuniShipping\PayuniShipping;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\SingletonTrait;


defined( 'ABSPATH' ) || exit;

class OrderEdit {

    use SingletonTrait;

	public static function init() {

			self::get_instance();
		
			add_filter( 'woocommerce_admin_shipping_fields', array( self::get_instance(), 'mo_payuni_shipping_cvs_fields' ), 10, 1 );
			
			// 建立 PAYUNi 物流單.
			add_filter( 'woocommerce_order_actions', array( self::get_instance(), 'payuni_order_actions' ) );
			
			add_filter( 'woocommerce_order_action_create_mo_payuni_shipping_order', array( ShippingRequest::get_instance(), 'payuni_create_shipping' ) );

			add_action( 'add_meta_boxes', array( __NAMESPACE__. '\\OrderMetaBox', 'add_meta_box' ), 40, 2 );
		
	}

	public static function mo_payuni_shipping_cvs_fields( $shipping_fields ) {
		global $theorder;
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $theorder ) ) {
			if ( isset( $_POST['post_ID'] ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WC core metabox callback injects $theorder; var name fixed by WC core API.
				$theorder = wc_get_order( absint( wp_unslash( $_POST['post_ID'] ) ) );
			} else {
				return $shipping_fields;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$shipping_method = false;

		$items_shipping = $theorder->get_items( 'shipping' );
		$items_shipping = array_shift( $items_shipping );

		if ( $items_shipping ) {
			$shipping_method = PayuniShipping::is_mo_payuni_shipping_cvs( $items_shipping->get_method_id() );
		}

		if ( false !== $shipping_method ) {

			$shipping_fields['payuni_storeid']      = array(
				'label' => __( 'Store ID', 'mo-ectools' ),
				'show'  => false,
			);
			$shipping_fields['payuni_storename']    = array(
				'label' => __( 'Store Name', 'mo-ectools' ),
				'show'  => false,
			);
			$shipping_fields['payuni_storeaddress'] = array(
				'label' => __( 'Store Address', 'mo-ectools' ),
				'show'  => false,
			);

			$shipping_fields['phone'] = array(
				'label' => __( 'Shipping Phone', 'mo-ectools' ),
			);
			
		} else {
			if ( $items_shipping ) {
				if ( PayuniShipping::is_mo_payuni_shipping_hd( $items_shipping->get_method_id() ) ) {
					$shipping_fields['phone'] = array(
						'label' => __( 'Shipping Phone', 'mo-ectools' ),
					);
				}
			}
		}

		return $shipping_fields;
	}

	public static function payuni_order_actions( $order_actions ) {
		global $theorder;

		foreach ( $theorder->get_items( 'shipping' ) as $item_id => $item ) {
			if ( PayuniShipping::is_payuni_shipping( $item->get_method_id() ) !== false ) {
				if ( empty( $theorder->get_meta( OrderMeta::ShipTradeNo ) ) ) {
					$order_actions['create_mo_payuni_shipping_order'] = __( 'Create PAYUNi Shipping Order', 'mo-ectools' );
				} else {
					$order_actions['create_mo_payuni_shipping_order'] = __( 'Re-Create PAYUNi Shipping Order', 'mo-ectools' );
				}
			}
		}
		return $order_actions;
	}

}
