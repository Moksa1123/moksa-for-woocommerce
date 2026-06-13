<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Frontend;

use MoksaWeb\Mowc\Modules\PayuniShipping\PayuniShipping;

defined( 'ABSPATH' ) || exit;

final class StoreValidation {

	
	public static function block_validate_cvs_store( \WC_Order $order, $request ): void {
		// 跳過試算 call（換金流 / 換物流時 Block 會打 __experimental_calc_totals=true，不是真下單）
		if ( $request && method_exists( $request, 'get_param' ) && $request->get_param( '__experimental_calc_totals' ) ) {
			return;
		}

		$is_cvs = false;
		foreach ( $order->get_shipping_methods() as $method ) {
			$mid = strstr( $method->get_method_id(), ':', true ) ?: $method->get_method_id();
			if ( isset( PayuniShipping::$cvs_methods[ $mid ] ) ) {
				$is_cvs = true;
				break;
			}
		}
		if ( ! $is_cvs ) {
			return;
		}

		$store_id = '';
		if ( WC()->session ) {
			$session_store = WC()->session->get( 'moksafowo_payuni_selected_store_data' );
			if ( is_array( $session_store ) && ! empty( $session_store['id'] ) ) {
				$store_id = (string) $session_store['id'];
			}
		}
		if ( '' !== $store_id ) {
			return;
		}

		if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'mowp_payuni_cvs_no_store',
				esc_html__( '請先選擇取貨門市。', 'mo-ectools' ),
				400
			);
		}
		throw new \Exception( esc_html__( '請先選擇取貨門市。', 'mo-ectools' ) );
	}

	public static function classic_fields_validation(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
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

		$store_id = '';
		if ( ! empty( $_POST['moksafowo_payuni_selected_store_id'] ) ) {
			$store_id = sanitize_text_field( wp_unslash( $_POST['moksafowo_payuni_selected_store_id'] ) );
		}
		if ( empty( $store_id ) && WC()->session ) {
			$session_store = WC()->session->get( 'moksafowo_payuni_selected_store_data' );
			if ( is_array( $session_store ) && ! empty( $session_store['id'] ) ) {
				$store_id = (string) $session_store['id'];
			}
		}

		if ( $need_cvs && empty( $store_id ) ) {
			wc_add_notice( __( '請選擇取貨超商門市。', 'mo-ectools' ), 'error' );
		}

		$shipping_phone = isset( $_POST['shipping_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_phone'] ) ) : '';
		if ( empty( $shipping_phone ) ) {
			$shipping_phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
		}
		if ( $need_cvs ) {
			// TW 預設驗證，海外站可 `add_filter('moksafowo_payuni_shipping_phone_valid', '__return_true')` 放寬
			$valid = (bool) preg_match( '/^[0][1-9]{1,3}[0-9]{6,8}$/', $shipping_phone )
				&& strlen( $shipping_phone ) >= 10 && strlen( $shipping_phone ) <= 11;
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
			$valid = (bool) apply_filters( 'moksafowo_payuni_shipping_phone_valid', $valid, $shipping_phone );
			if ( ! $valid ) {
				wc_add_notice( __( 'Shipping Phone format is invalid', 'mo-ectools' ), 'error' );
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
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
