<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Frontend;

use MoksaWeb\Mowc\Modules\PayuniShipping\PayuniShipping;

defined( 'ABSPATH' ) || exit;

final class EnqueueScripts {

	public static function checkout(): void {
		if ( ! is_checkout() ) {
			return;
		}
		wc_setcookie( 'payuni_checkout_url', get_permalink( get_the_ID() ), 0 );

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

		wp_register_script( 'mo-payuni-shipping', MOWC_PLUGIN_URL . 'src/Modules/PayuniShipping/assets/js/scripts-public.js', [ 'jquery' ], MOWC_VERSION, true );
		wp_enqueue_script( 'mo-payuni-shipping' );
		wp_enqueue_style( 'mo-payuni-shipping', MOWC_PLUGIN_URL . 'src/Modules/PayuniShipping/assets/css/styles-public.css', [], MOWC_VERSION, 'all' );
	}

	public static function admin(): void {
		wp_enqueue_style( 'mo-payuni-shipping-admin', MOWC_PLUGIN_URL . 'src/Modules/PayuniShipping/assets/css/styles-admin.css', [], MOWC_VERSION, 'all' );
		wp_enqueue_script( 'mo-payuni-shipping-admin', MOWC_PLUGIN_URL . 'src/Modules/PayuniShipping/assets/js/scripts-admin.js', [ 'jquery' ], MOWC_VERSION, false );
		wp_localize_script(
			'mo-payuni-shipping-admin',
			'mo_payuni_shipping',
			[
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'security'     => wp_create_nonce( 'payuni-shipping-order' ),
				'translations' => [
					'shipping_status_update_failed' => __( 'Shipping status update failed.', 'mo-ectools' ),
					'cancel_shipping_failed'        => __( 'Shipping order cancel failed.', 'mo-ectools' ),
				],
			]
		);
	}
}
