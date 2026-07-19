<?php

namespace Moksafowo\Modules\PayuniShipping\Api;

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\PayuniShipping\Utils\SingletonTrait;
use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Utils\LgsType;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;
use Moksafowo\Modules\PayuniShipping\Utils\ShippingStatus;

class ShippingResponse {

	use SingletonTrait;

	public static function init() {
		self::get_instance();

		add_action( 'woocommerce_api_moksafowo_payuni_shipping_711_notify', array( self::get_instance(), 'moksafowo_payuni_711_receive_update' ) );
		add_action( 'woocommerce_api_moksafowo_payuni_shipping_tcat_notify', array( self::get_instance(), 'moksafowo_payuni_tcat_receive_update' ) );
		add_action( 'moksafowo_payuni_update_shipping_order_status', array( self::get_instance(), 'update_order_status_after_received_update' ), 10, 3 );
	}

	public static function moksafowo_payuni_711_receive_update() {
		// PAYUNi shipping 7-11 webhook: no WP nonce possible (external server cannot send one).
		// Source authenticity verified via HashInfo (hash_equals, PayuniShipping::hash_info) before decryption.
		// $_POST sanitized via wc_clean + wp_unslash before use; decrypted payload deep-sanitized via map_deep below.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- PAYUNi shipping IPN; no WP nonce possible; source verified via HashInfo hash_equals before decryption and any state change; sanitized via wc_clean and map_deep.
		$posted = wc_clean( wp_unslash( $_POST ) );

		$encrypt_info = array_key_exists( 'EncryptInfo', $posted ) ? $posted['EncryptInfo'] : '';
		$posted_hash  = array_key_exists( 'HashInfo', $posted ) ? $posted['HashInfo'] : '';

		// SECURITY: HashInfo 必須在解密前驗章，防止偽造貨態通知
		if ( '' === $encrypt_info || '' === $posted_hash || ! hash_equals( PayuniShipping::hash_info( $encrypt_info ), strtoupper( $posted_hash ) ) ) {
			PayuniShipping::log( '7-11 notify HashInfo mismatch — rejected.' );
			status_header( 403 );
			return;
		}

		$decrypted_info = PayuniShipping::decrypt( $encrypt_info );
		if ( is_array( $decrypted_info ) ) {
			$decrypted_info = map_deep( $decrypted_info, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
		}
		PayuniShipping::log( 'PAYUNi 7-11 notify decrypted: ShipTradeNo=' . ( $decrypted_info['ShipTradeNo'] ?? '?' ) . ', Status=' . ( $decrypted_info['ShipStatus'] ?? '?' ) );

		if ( 'SUCCESS' === $decrypted_info['Status'] ) {

			if ( ! array_key_exists( 'ApiType', $decrypted_info ) ) {
				PayuniShipping::log( 'PAYUNi NotifyURL response ApiType not found in response:' . wc_print_r( $decrypted_info, true ) );
				return;
			}

			if ( 'ShipStatus' === $decrypted_info['ApiType'] ) {
				$orders = wc_get_orders(
					array(
						'limit'        => -1,
						'orderby'      => 'date',
						'order'        => 'DESC',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
						'meta_key'     => OrderMeta::ShipTradeNo,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
						'meta_value'   => $decrypted_info['ShipTradeNo'],
						'meta_compare' => '=',
					)
				);

				if ( ! empty( $orders ) ) {

					$order = $orders[0];

					if ( $order->get_meta( OrderMeta::ShipTradeNo ) !== $decrypted_info['ShipTradeNo'] ) {
						PayuniShipping::log( 'ShipTradeNo mismatch. Order id:' . $order->get_id() . ', ShipTradeNo:' . $order->get_meta( OrderMeta::ShipTradeNo ) . ', received ShipTradeNo:' . $decrypted_info['ShipTradeNo'] );
						return;
					}

					if ( array_key_exists( 'ShipStatus', $decrypted_info ) ) {
						$order->update_meta_data( OrderMeta::ShipStatus, $decrypted_info['ShipStatus'] );
						$order->update_meta_data( OrderMeta::ShipStatusDesc, $decrypted_info['ShipStatusDesc'] );
						$order->update_meta_data( OrderMeta::ShipStatusTime, $decrypted_info['ShipStatusTime'] );
						$order->save();

						$shipping_log = _x( 'PAYUNi Shipping Notify', 'Shipping Note', 'moksa-for-woocommerce' );
						$shiptrade_no = _x( 'ShipTradeNo: ', 'Shipping Note', 'moksa-for-woocommerce' );
						$ship_status  = _x( 'ShipStatus: ', 'Shipping Note', 'moksa-for-woocommerce' );
						$ship_desc    = _x( 'ShipStatusDesc: ', 'Shipping Note', 'moksa-for-woocommerce' );
						$ship_time    = _x( 'ShipStatusTime: ', 'Shipping Note', 'moksa-for-woocommerce' );

						$order->add_order_note( "<strong>{$shipping_log}</strong><br>{$shiptrade_no}{$decrypted_info['ShipTradeNo']}<br>{$ship_status}{$decrypted_info['ShipStatus']}<br>{$ship_desc}{$decrypted_info['ShipStatusDesc']}<br>{$ship_time}{$decrypted_info['ShipStatusTime']}" );

						do_action( 'moksafowo_payuni_update_shipping_order_status', $order, $decrypted_info['ShipStatus'], $decrypted_info['ShipStatusDesc'] );
					}
				}
			} elseif ( 'Print' === $decrypted_info['ApiType'] ) {
				$orders = wc_get_orders(
					array(
						'limit'        => -1,
						'orderby'      => 'date',
						'order'        => 'DESC',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
						'meta_key'     => OrderMeta::ShipTradeNo,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
						'meta_value'   => $decrypted_info['ShipTradeNo'],
						'meta_compare' => '=',
					)
				);

				if ( ! empty( $orders ) ) {
					$order = $orders[0];

					if ( $order->get_meta( OrderMeta::ShipTradeNo ) !== $decrypted_info['ShipTradeNo'] ) {
						PayuniShipping::log( 'ShipTradeNo mismatch. Order id:' . $order->get_id() . ', ShipTradeNo:' . $order->get_meta( OrderMeta::ShipTradeNo ) . ', received ShipTradeNo:' . $decrypted_info['ShipTradeNo'] );
						return;
					}

					if ( array_key_exists( 'Odno', $decrypted_info ) ) {
						$order->update_meta_data( OrderMeta::PartnerId, $decrypted_info['PartnerId'] );
						$validation_no = array_key_exists( 'ValidationNo', $decrypted_info ) ? $decrypted_info['ValidationNo'] : '';
						$order->update_meta_data( OrderMeta::ShipNo, self::build_ship_no( $decrypted_info['LgsType'], $decrypted_info['PartnerId'], $decrypted_info['Odno'], $validation_no ) );
						$order->update_meta_data( OrderMeta::Odno, $decrypted_info['Odno'] );
						$order->update_meta_data( OrderMeta::ValidationNo, $validation_no );
						$order->save();
					}

					$shipping_log  = _x( 'PAYUNi Shipping Print Notify', 'Shipping Note', 'moksa-for-woocommerce' );
					$print_status  = _x( 'Print Status: ', 'Shipping Note', 'moksa-for-woocommerce' );
					$print_message = _x( 'Message: ', 'Shipping Note', 'moksa-for-woocommerce' );

					$order->add_order_note( "<strong>{$shipping_log}</strong><br>{$print_status}{$decrypted_info['Status']}<br>{$print_message}{$decrypted_info['Message']}" );

				}
			} else {
				PayuniShipping::log( 'PAYUNi NotifyURL response ApiType unknown:' . $decrypted_info['ApiType'] );
			}
		} else {
			PayuniShipping::log( 'PAYUNi NotifyURL response fail:' . wc_print_r( $decrypted_info, true ) );
		}
	}

	public static function moksafowo_payuni_tcat_receive_update() {
		// PAYUNi shipping TCat webhook: no WP nonce possible (external server cannot send one).
		// Source authenticity verified via HashInfo (hash_equals, PayuniShipping::hash_info) before decryption.
		// $_POST sanitized via wc_clean + wp_unslash before use; decrypted payload deep-sanitized via map_deep below.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- PAYUNi shipping IPN; no WP nonce possible; source verified via HashInfo hash_equals before decryption and any state change; sanitized via wc_clean and map_deep.
		$posted = wc_clean( wp_unslash( $_POST ) );

		$encrypt_info = array_key_exists( 'EncryptInfo', $posted ) ? $posted['EncryptInfo'] : '';
		$posted_hash  = array_key_exists( 'HashInfo', $posted ) ? $posted['HashInfo'] : '';

		// SECURITY: HashInfo 必須在解密前驗章，防止偽造貨態通知
		if ( '' === $encrypt_info || '' === $posted_hash || ! hash_equals( PayuniShipping::hash_info( $encrypt_info ), strtoupper( $posted_hash ) ) ) {
			PayuniShipping::log( 'TCat notify HashInfo mismatch — rejected.' );
			status_header( 403 );
			return;
		}

		$decrypted_info = PayuniShipping::decrypt( $encrypt_info );
		if ( is_array( $decrypted_info ) ) {
			$decrypted_info = map_deep( $decrypted_info, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
		}
		PayuniShipping::log( 'PAYUNi TCat notify decrypted: ShipTradeNo=' . ( $decrypted_info['ShipTradeNo'] ?? '?' ) . ', Status=' . ( $decrypted_info['ShipStatus'] ?? '?' ) );

		if ( 'SUCCESS' === $decrypted_info['Status'] ) {

			if ( ! array_key_exists( 'ApiType', $decrypted_info ) ) {
				PayuniShipping::log( 'PAYUNi NotifyURL response ApiType not found in response:' . wc_print_r( $decrypted_info, true ) );
				return;
			}

			if ( 'ShipStatus' === $decrypted_info['ApiType'] ) {
				$orders = wc_get_orders(
					array(
						'limit'        => -1,
						'orderby'      => 'date',
						'order'        => 'DESC',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
						'meta_key'     => OrderMeta::ShipTradeNo,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
						'meta_value'   => $decrypted_info['ShipTradeNo'],
						'meta_compare' => '=',
					)
				);

				if ( ! empty( $orders ) ) {

					$order = $orders[0];

					if ( $order->get_meta( OrderMeta::ShipTradeNo ) !== $decrypted_info['ShipTradeNo'] ) {
						PayuniShipping::log( 'ShipTradeNo mismatch. Order id:' . $order->get_id() . ', ShipTradeNo:' . $order->get_meta( OrderMeta::ShipTradeNo ) . ', received ShipTradeNo:' . $decrypted_info['ShipTradeNo'] );
						return;
					}

					if ( 'ShipStatus' === $decrypted_info['ApiType'] ) {
						$order->update_meta_data( OrderMeta::ShipNo, $decrypted_info['OBTNumber'] );
						$order->update_meta_data( OrderMeta::ShipStatus, $decrypted_info['ShipStatus'] );
						$order->update_meta_data( OrderMeta::ShipStatusDesc, $decrypted_info['ShipStatusDesc'] );
						$order->update_meta_data( OrderMeta::ShipStatusTime, $decrypted_info['ShipStatusTime'] );
					}

					if ( array_key_exists( 'FileNo', $decrypted_info ) ) {
						$order->update_meta_data( OrderMeta::FileNo, $decrypted_info['FileNo'] );
					}
					$order->save();

					if ( array_key_exists( 'ShipStatus', $decrypted_info ) ) {

						$shipping_log = _x( 'PAYUNi Shipping Notify', 'Shipping Note', 'moksa-for-woocommerce' );
						$shiptrade_no = _x( 'ShipTradeNo: ', 'Shipping Note', 'moksa-for-woocommerce' );
						$ship_status  = _x( 'ShipStatus: ', 'Shipping Note', 'moksa-for-woocommerce' );
						$ship_desc    = _x( 'ShipStatusDesc: ', 'Shipping Note', 'moksa-for-woocommerce' );
						$ship_time    = _x( 'ShipStatusTime: ', 'Shipping Note', 'moksa-for-woocommerce' );

						$order->add_order_note( "<strong>{$shipping_log}</strong><br>{$shiptrade_no}{$decrypted_info['ShipTradeNo']}<br>{$ship_status}{$decrypted_info['ShipStatus']}<br>{$ship_desc}{$decrypted_info['ShipStatusDesc']}<br>{$ship_time}{$decrypted_info['ShipStatusTime']}" );

						do_action( 'moksafowo_payuni_update_shipping_order_status', $order, $decrypted_info['ShipStatus'], $decrypted_info['ShipStatusDesc'] );
					}
				} else {
					PayuniShipping::log( 'PAYUNi NotifyURL response fail: can not find order by ShipTradeNo:' . $decrypted_info['ShipTradeNo'] );
				}
			} elseif ( 'Print' === $decrypted_info['ApiType'] ) {
				$raw_json  = is_string( $decrypted_info['JsonData'] ?? null ) ? $decrypted_info['JsonData'] : '';
				$json_data = $raw_json !== '' ? json_decode( $raw_json, true ) : null;
				if ( ! is_array( $json_data ) || ! isset( $json_data[0] ) || ! is_array( $json_data[0] ) ) {
					PayuniShipping::log( 'PAYUNi NotifyURL tcat print: JsonData parse failed' );
					exit;
				}
				// json_decode does not sanitize — deep-sanitize before any use.
				$json_data    = map_deep( $json_data, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
				$print_result = $json_data[0];
				if ( 'SUCCESS' === $print_result['Status'] ) {
					$ship_trade_no = $print_result['ShipTradeNo'];
					$orders        = wc_get_orders(
						array(
							'limit'        => -1,
							'orderby'      => 'date',
							'order'        => 'DESC',
							// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
							'meta_key'     => OrderMeta::ShipTradeNo,
							// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
							'meta_value'   => $ship_trade_no,
							'meta_compare' => '=',
						)
					);

					if ( ! empty( $orders ) ) {
						$order = $orders[0];
						$order->update_meta_data( OrderMeta::FileNo, $print_result['FileNo'] );
						$order->update_meta_data( OrderMeta::PrintDate, $print_result['PrintDate'] );
						$order->save();

						$shipping_log  = _x( 'PAYUNi Shipping Print Notify', 'Shipping Note', 'moksa-for-woocommerce' );
						$print_status  = _x( 'Print Status: ', 'Shipping Note', 'moksa-for-woocommerce' );
						$print_message = _x( 'Message: ', 'Shipping Note', 'moksa-for-woocommerce' );

						$order->add_order_note( "<strong>{$shipping_log}</strong><br>{$print_status}{$decrypted_info['Status']}<br>{$print_message}{$decrypted_info['Message']}" );

					} else {
						PayuniShipping::log( 'PAYUNi NotifyURL response tcat print fail: can not find order by ShipTradeNo:' . $ship_trade_no );
					}
				} else {
					PayuniShipping::log( 'PAYUNi NotifyURL response tcat print fail: ' . wc_print_r( $print_result, true ) );
				}
			}
		} else {
			PayuniShipping::log( 'PAYUNi NotifyURL response fail:' . wc_print_r( $decrypted_info, true ) );
		}
		exit;
	}

	public function update_order_status_after_received_update( \WC_Order $order, $ship_status, $ship_status_desc ) {

		PayuniShipping::log( 'Update order stauts. Order id:' . $order->get_id() . ', ship_status:' . $ship_status );

		if ( ShippingStatus::AT_LOGISTIC_CENTER === $ship_status && strpos( $ship_status_desc, 'EIN00' ) !== false && $order->get_meta( OrderMeta::LgsType ) === LgsType::B2C ) {
			if ( ! empty( PayuniShipping::$order_status_at_logistic_center ) ) {
					$order->update_status( PayuniShipping::$order_status_at_logistic_center );
			}
		} elseif ( ShippingStatus::AT_SENDER_CVS === $ship_status && strpos( $ship_status_desc, 'AOL' ) !== false && $order->get_meta( OrderMeta::LgsType ) === LgsType::C2C ) {
			if ( ! empty( PayuniShipping::$order_status_at_sender_cvs ) ) {
				$order->update_status( PayuniShipping::$order_status_at_sender_cvs );
			}
		} elseif ( ShippingStatus::DELIVERING === $ship_status ) {
			if ( ! empty( PayuniShipping::$order_status_delivering ) ) {
				$order->update_status( PayuniShipping::$order_status_delivering );
			}
		} elseif ( ShippingStatus::AT_RECEIVER_CVS === $ship_status ) {
			if ( ! empty( PayuniShipping::$order_status_at_receiver_cvs ) ) {
				$order->update_status( PayuniShipping::$order_status_at_receiver_cvs );
			}
		} elseif ( ShippingStatus::CUSTOMER_PICKUP === $ship_status ) {
			if ( ! empty( PayuniShipping::$order_status_pickuped ) ) {
				$order->update_status( PayuniShipping::$order_status_pickuped );
			}
		}
	}

	public static function build_ship_no( $lgs_type, $partner_id = '', $odno = '', $validation_no = '' ) {
		if ( $lgs_type === LgsType::C2C ) {
			return $odno . $validation_no;
		} elseif ( $lgs_type === LgsType::B2C ) {
			return $partner_id . $odno;
		}
		return '';
	}
}
