<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use MoksaWeb\Mowc\Modules\Linepay\Constants;
use MoksaWeb\Mowc\Modules\Linepay\LinePay;
use WC_Order;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox {

	private static $instance;

	public static function init(): void {
		self::get_instance();
		add_action( 'add_meta_boxes', array( self::get_instance(), 'add_meta_box' ), 10, 2 );
	}

	public function add_meta_box( $post_type, $post_or_order_object ): void {

		$order = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! array_key_exists( $order->get_payment_method(), LinePay::$allowed_payments ) ) {
			return;
		}

		$screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'woocommerce-linepay-meta-boxes',
			__( 'LINE Pay 交易資訊', 'mo-ectools' ),
			array( self::get_instance(), 'render_meta' ),
			$screen,
			'side',
			'high'
		);
	}

	public function render_meta( $post_or_order_object ): void {

		$order = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( array_key_exists( $order->get_payment_method(), LinePay::$allowed_payments ) ) {

			$raw_status    = (string) $order->get_meta( '_linepay_payment_status' );
			$status_labels = array(
				Constants::PAYMENT_STATUS_RESERVED  => __( '已預授權（待請款）', 'mo-ectools' ),
				Constants::PAYMENT_STATUS_AUTHED    => __( '已授權（待請款）', 'mo-ectools' ),
				Constants::PAYMENT_STATUS_CONFIRMED => __( '已請款', 'mo-ectools' ),
				Constants::PAYMENT_STATUS_CANCELLED => __( '已取消', 'mo-ectools' ),
				Constants::PAYMENT_STATUS_REFUNDED  => __( '已退款', 'mo-ectools' ),
				Constants::PAYMENT_STATUS_FAILED    => __( '失敗', 'mo-ectools' ),
			);
			$status_label  = $status_labels[ $raw_status ] ?? $raw_status;

			echo '<table>';
			echo '<tr><th><div id="order-id" data-order-id="' . esc_html( (string) $order->get_id() ) . '">' . esc_html__( '交易編號', 'mo-ectools' ) . '</div></th><td>' . esc_html( (string) $order->get_meta( '_linepay_reserved_transaction_id' ) ) . '</td></tr>';
			echo '<tr><th><div>' . esc_html__( '交易狀態', 'mo-ectools' ) . '</div></th><td>' . esc_html( $status_label ) . '</td></tr>';

			if ( Constants::PAYMENT_STATUS_AUTHED === $raw_status ) {
				echo '<tr id="linepay-action"><th>' . esc_html__( '付款動作', 'mo-ectools' ) . '</th><td><button class="button linepay-confirm-btn" data-id=' . esc_html( (string) $order->get_id() ) . '>' . esc_html__( '確認請款', 'mo-ectools' ) . '</button></tr>';
			}

			echo '</table>';
		}
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// do nothing.
	}
}
