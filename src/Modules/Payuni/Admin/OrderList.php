<?php

namespace Moksafowo\Modules\Payuni\Admin;

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\Payuni\PayuniPayment;
use Moksafowo\Modules\Payuni\Utils\OrderMeta;
use Moksafowo\Modules\Payuni\Utils\SingletonTrait;

class OrderList {

	use SingletonTrait;

	public static function init() {
		self::get_instance();

		add_filter( 'manage_shop_order_posts_columns', array( self::get_instance(), 'shop_order_columns' ), 20, 1 );
		add_action( 'manage_shop_order_posts_custom_column', array( self::get_instance(), 'shop_order_column' ), 20, 2 );
	}

	public function shop_order_columns( $columns ) {

		if ( ! PayuniPayment::$einvoice_enabled ) {
			return $columns;
		}

		if ( $columns['wmp_invoice_no'] ) {
			unset( $columns['wmp_invoice_no'] );
		}

		$add_index   = array_search( 'shipping_address', array_keys( $columns ), true ) + 1;
		$pre_array   = array_splice( $columns, 0, $add_index );
		$new_columns = array(
			'moksafowo_payuni_invoice_no' => __( '發票號碼', 'mo-ectools' ),
		);
		return array_merge( $pre_array, $new_columns, $columns );
	}

	public function shop_order_column( $column, $post_id ) {
		if ( 'moksafowo_payuni_invoice_no' === $column ) {
			$order      = wc_get_order( $post_id );
			$invoice_no = $order->get_meta( OrderMeta::EINVOICE_NO );
			if ( $invoice_no ) {
				echo esc_html( $invoice_no );
			} else {
				echo esc_html__( '未開立', 'mo-ectools' );
			}
		}
	}
}
