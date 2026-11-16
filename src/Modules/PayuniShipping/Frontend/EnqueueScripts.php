<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Frontend;

use Moksafowo\Modules\PayuniShipping\PayuniShipping;

defined( 'ABSPATH' ) || exit;

final class EnqueueScripts {

	public static function checkout(): void {
		if ( ! is_checkout() ) {
			return;
		}
		wc_setcookie( 'moksafowo_payuni_checkout_url', get_permalink( get_the_ID() ), 0 );

		if ( ! WC()->cart->needs_shipping() ) {
			return;
		}
		$chosen = WC()->session->get( 'chosen_shipping_methods' );
		if ( empty( $chosen ) ) {
			return;
		}

		$has_payuni = false;
		foreach ( $chosen as $method ) {
			if ( PayuniShipping::is_payuni_shipping( $method ) ) {
				$has_payuni = true;
				break;
			}
		}
		if ( ! $has_payuni ) {
			return;
		}

		wp_register_script( 'moksafowo-payuni-shipping', MOKSAFOWO_PLUGIN_URL . 'src/Modules/PayuniShipping/assets/js/scripts-public.js', [ 'jquery' ], MOKSAFOWO_VERSION, true );
		wp_enqueue_script( 'moksafowo-payuni-shipping' );
		wp_enqueue_style( 'moksafowo-payuni-shipping', MOKSAFOWO_PLUGIN_URL . 'src/Modules/PayuniShipping/assets/css/styles-public.css', [], MOKSAFOWO_VERSION, 'all' );
	}

	public static function admin(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders', 'woocommerce_page_wc-settings' ], true ) ) {
			return;
		}
		wp_enqueue_style( 'moksafowo-payuni-shipping-admin', MOKSAFOWO_PLUGIN_URL . 'src/Modules/PayuniShipping/assets/css/styles-admin.css', [], MOKSAFOWO_VERSION, 'all' );
		wp_enqueue_script( 'moksafowo-payuni-shipping-admin', MOKSAFOWO_PLUGIN_URL . 'src/Modules/PayuniShipping/assets/js/scripts-admin.js', [ 'jquery' ], (string) filemtime( MOKSAFOWO_PLUGIN_DIR . 'src/Modules/PayuniShipping/assets/js/scripts-admin.js' ), true );
		wp_localize_script(
			'moksafowo-payuni-shipping-admin',
			'moksafowo_payuni_shipping',
			[
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'security'     => wp_create_nonce( 'moksafowo-payuni-shipping-order' ),
				'translations' => [
					'shipping_status_update_failed' => __( 'The shipping status could not be updated.', 'moksa-for-woocommerce' ),
					'cancel_shipping_failed'        => __( 'The shipment could not be cancelled.', 'moksa-for-woocommerce' ),
					'updating'                      => __( 'Updating…', 'moksa-for-woocommerce' ),
					'updated'                       => __( 'Updated', 'moksa-for-woocommerce' ),
					'update_failed'                 => __( 'Update failed', 'moksa-for-woocommerce' ),
					'request_failed'                => __( 'The request failed. Please try again.', 'moksa-for-woocommerce' ),
					'ship_tcat'                     => __( 'T-Cat home delivery', 'moksa-for-woocommerce' ),
					'ship_711'                      => __( '7-ELEVEN pickup', 'moksa-for-woocommerce' ),
					/* translators: %s: shipping method name, e.g. T-Cat home delivery */
					'batch_title'                   => __( 'Batch print %s labels', 'moksa-for-woocommerce' ),
					/* translators: %s: shipping method name */
					'list_desc'                     => __( '%s orders (up to 50 shown)', 'moksa-for-woocommerce' ),
					'col_order'                     => __( 'Order number', 'moksa-for-woocommerce' ),
					'col_recipient'                 => __( 'Recipient', 'moksa-for-woocommerce' ),
					'col_method'                    => __( 'Shipping method', 'moksa-for-woocommerce' ),
					'col_status'                    => __( 'Status', 'moksa-for-woocommerce' ),
					'col_spec'                      => __( 'Parcel size', 'moksa-for-woocommerce' ),
					'col_tracking'                  => __( 'Tracking number', 'moksa-for-woocommerce' ),
					/* translators: %s: shipping method name */
					'none_to_print'                 => __( 'There are no %s orders to print.', 'moksa-for-woocommerce' ),
					'load_failed'                   => __( 'The orders could not be loaded. Please try again later.', 'moksa-for-woocommerce' ),
					'none_match'                    => __( 'No orders match the filter.', 'moksa-for-woocommerce' ),
					'all'                           => __( 'All', 'moksa-for-woocommerce' ),
					/* translators: 1: orders shown, 2: all orders */
					'filtered'                      => __( 'Filtered: showing %1$s of %2$s orders', 'moksa-for-woocommerce' ),
					'tcat_same_spec'                => __( 'To batch print T-Cat labels, all orders must have the same temperature and parcel size. Use the filter or select the orders again.', 'moksa-for-woocommerce' ),
					'processing'                    => __( 'Processing…', 'moksa-for-woocommerce' ),
					/* translators: 1: processed order count, 2: failed order count */
					'done_with_errors'              => __( '%1$s orders processed, %2$s failed', 'moksa-for-woocommerce' ),
					/* translators: %s: processed order count */
					'done'                          => __( '%s orders processed', 'moksa-for-woocommerce' ),
					/* translators: %s: print page URL */
					'popup_blocked'                 => __( 'The browser blocked the pop-up window. Allow pop-ups, or open this link yourself: %s', 'moksa-for-woocommerce' ),
					/* translators: %s: error message from PAYUNi */
					'print_failed'                  => __( 'Printing failed: %s', 'moksa-for-woocommerce' ),
					'generic_error'                 => __( 'Something went wrong. Please try again later.', 'moksa-for-woocommerce' ),
				],
			]
		);
	}
}
