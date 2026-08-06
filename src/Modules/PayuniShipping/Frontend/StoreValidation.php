<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Frontend;

use Moksafowo\Modules\PayuniShipping\PayuniShipping;

defined( 'ABSPATH' ) || exit;

final class StoreValidation {


	public static function classic_fields_validation(): void {
		// 與 WC core 的 process_checkout() 驗同一顆 nonce，同一種寫法（woocommerce_checkout_process 必帶）。
		if ( ! isset( $_POST['woocommerce-process-checkout-nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['woocommerce-process-checkout-nonce'] ) ), 'woocommerce-process_checkout' ) ) {
			return;
		}
		$shipping_methods = isset( $_POST['shipping_method'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['shipping_method'] ) ) : [];

		$need_cvs = false;
		$need_hd  = false;
		foreach ( $shipping_methods as $method ) {
			$base = strstr( $method, ':', true );
			if ( array_key_exists( $base, PayuniShipping::$cvs_methods ) ) {
				$need_cvs = true;
			}
			if ( array_key_exists( $base, PayuniShipping::$hd_methods ) ) {
				$need_hd = true;
			}
		}

		// 「選了超商卻沒選門市」的擋單已移到共用的 Shipping\Frontend\CvsStoreRequired
		// （Classic + Block、四家一致）。這裡只留 PAYUNi 專屬的收件電話驗證與欄位必填切換。

		$shipping_phone = isset( $_POST['shipping_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_phone'] ) ) : '';
		if ( empty( $shipping_phone ) ) {
			$shipping_phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
		}
		if ( $need_cvs ) {
			// TW 預設驗證，海外站可 `add_filter('moksafowo_payuni_shipping_phone_valid', '__return_true')` 放寬
			$valid = (bool) preg_match( '/^[0][1-9]{1,3}[0-9]{6,8}$/', $shipping_phone )
				&& strlen( $shipping_phone ) >= 10 && strlen( $shipping_phone ) <= 11;
			$valid = (bool) apply_filters( 'moksafowo_payuni_shipping_phone_valid', $valid, $shipping_phone );
			if ( ! $valid ) {
				wc_add_notice( __( 'The recipient\'s phone number is not valid. Please enter a working mobile or landline number.', 'moksa-for-woocommerce' ), 'error' );
			}
		}

		if ( empty( $_POST['shipping_country'] ) ) {
			$_POST['shipping_country'] = 'TW';
		}

		$instance = PayuniShipping::get_instance();
		if ( $need_cvs ) {
			add_filter( 'woocommerce_checkout_fields', [ $instance, 'setup_cvs_shipping_fields_requirements' ], 9999 );
		} else {
			add_filter( 'woocommerce_checkout_fields', [ $instance, 'remove_shipping_phone_required' ], 9999 );
		}
	}
}
