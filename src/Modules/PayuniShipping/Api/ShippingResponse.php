<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Api;

defined( 'ABSPATH' ) || exit;

use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\SingletonTrait;
use MoksaWeb\Mowc\Modules\PayuniShipping\PayuniShipping;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\LgsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShippingStatus;

class ShippingResponse {

    use SingletonTrait;

    	public static function init() {
		self::get_instance();

		// 7-11 物流貨態回傳.
		add_action( 'woocommerce_api_mo_payuni_shipping_711_notify', array( self::get_instance(), 'payuni_711_receive_update' ) );

		// TCAT 物流貨態回傳.
		add_action( 'woocommerce_api_mo_payuni_shipping_tcat_notify', array( self::get_instance(), 'payuni_tcat_receive_update' ) );

		// 根據貨態更新訂單狀態.
		add_action( 'payuni_update_shipping_order_status', array( self::get_instance(), 'update_order_status_after_received_update' ), 10, 3 );
	}

	public static function payuni_711_receive_update() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Gateway webhook; HashInfo / EncryptInfo signature verified inside this method.
		$posted = wc_clean( wp_unslash( $_POST ) );

		$encrypt_info = array_key_exists( 'EncryptInfo', $posted )? $posted['EncryptInfo']: '';
		$posted_hash  = array_key_exists( 'HashInfo', $posted )? $posted['HashInfo']: '';

		// SECURITY: verify HashInfo BEFORE decrypting. wpbr-payuni-shipping skipped
		// this step entirely. The decryption itself uses the same shared key as
		// the merchant, so an attacker who knew the key could otherwise forge a
		// shipment-status update.
		if ( '' === $encrypt_info || '' === $posted_hash || ! hash_equals( PayuniShipping::hash_info( $encrypt_info ), strtoupper( $posted_hash ) ) ) {
			PayuniShipping::log( '7-11 notify HashInfo mismatch — rejected.' );
			status_header( 403 );
			return;
		}

		$decrypted_info = PayuniShipping::decrypt( $encrypt_info );
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

						$shipping_log = _x( 'PAYUNi Shipping Notify', 'Shipping Note', 'mo-ectools' );
						$shiptrade_no = _x( 'ShipTradeNo: ', 'Shipping Note', 'mo-ectools' );
						$ship_status  = _x( 'ShipStatus: ', 'Shipping Note', 'mo-ectools' );
						$ship_desc    = _x( 'ShipStatusDesc: ', 'Shipping Note', 'mo-ectools' );
						$ship_time    = _x( 'ShipStatusTime: ', 'Shipping Note', 'mo-ectools' );

						$order->add_order_note( "<strong>{$shipping_log}</strong><br>{$shiptrade_no}{$decrypted_info['ShipTradeNo']}<br>{$ship_status}{$decrypted_info['ShipStatus']}<br>{$ship_desc}{$decrypted_info['ShipStatusDesc']}<br>{$ship_time}{$decrypted_info['ShipStatusTime']}" );

						// change order status based on ShipStatus.
						do_action( 'payuni_update_shipping_order_status', $order, $decrypted_info['ShipStatus'], $decrypted_info['ShipStatusDesc'] );
					}
				}// end empty $orders

			} elseif ( 'Print' === $decrypted_info['ApiType'] ) {
				// 7-11 列印宅配單.
				// (
				// 	[Status] => SUCCESS
				// 	[Message] => 訂單列印處理成功
				// 	[MerID] => S04061198
				// 	[ShipTradeNo] => S0011744703634423585
				// 	[GoodsType] => 1
				// 	[LgsType] => C2C
				// 	[ShipType] => 1
				// 	[PartnerId] => 8B2
				// 	[Odno] => S1329563
				// 	[ApiType] => Print
				// 	[ValidationNo] => 9940
				// )
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
						// 物流查詢編號 Odno+ValidationNo.
						$validation_no = array_key_exists( 'ValidationNo', $decrypted_info ) ? $decrypted_info['ValidationNo'] : '';
						$order->update_meta_data( OrderMeta::ShipNo, self::build_ship_no( $decrypted_info['LgsType'], $decrypted_info['PartnerId'], $decrypted_info['Odno'], $validation_no ) );
						$order->update_meta_data( OrderMeta::Odno, $decrypted_info['Odno'] );
						$order->update_meta_data( OrderMeta::ValidationNo, $validation_no );
						$order->save();
					}

					$shipping_log   = _x( 'PAYUNi Shipping Print Notify', 'Shipping Note', 'mo-ectools' );
					$print_status   = _x( 'Print Status: ', 'Shipping Note', 'mo-ectools' );
					$print_message  = _x( 'Message: ', 'Shipping Note', 'mo-ectools' );

					$order->add_order_note( "<strong>{$shipping_log}</strong><br>{$print_status}{$decrypted_info['Status']}<br>{$print_message}{$decrypted_info['Message']}" );

				}
			} else {
				PayuniShipping::log( 'PAYUNi NotifyURL response ApiType unknown:' . $decrypted_info['ApiType'] );
			}
		} else {
			PayuniShipping::log( 'PAYUNi NotifyURL response fail:' . wc_print_r( $decrypted_info, true ) );
		}
	}

	public static function payuni_tcat_receive_update() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Gateway webhook; HashInfo / EncryptInfo signature verified inside this method.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$posted = wc_clean( wp_unslash( $_POST ) );

		$encrypt_info = array_key_exists( 'EncryptInfo', $posted ) ? $posted['EncryptInfo'] : '';
		$posted_hash  = array_key_exists( 'HashInfo', $posted ) ? $posted['HashInfo'] : '';

		// SECURITY: same HashInfo verification as the 7-11 notify above.
		if ( '' === $encrypt_info || '' === $posted_hash || ! hash_equals( PayuniShipping::hash_info( $encrypt_info ), strtoupper( $posted_hash ) ) ) {
			PayuniShipping::log( 'TCat notify HashInfo mismatch — rejected.' );
			status_header( 403 );
			return;
		}

		$decrypted_info = PayuniShipping::decrypt( $encrypt_info );
		PayuniShipping::log( 'PAYUNi TCat notify decrypted: ShipTradeNo=' . ( $decrypted_info['ShipTradeNo'] ?? '?' ) . ', Status=' . ( $decrypted_info['ShipStatus'] ?? '?' ) );

		// [ShipTradeNo] => SC170141125758007251
		// [TradeType] => 1
		// [OBTNumber] => 620006439963 //宅配單號
		// [LgsType] => HOME
		// [GoodsType] => 1
		// [ShipType] => 2
		// [FileNo] => WwXCoTgL2x48q56web5GiHSMI6lF9Lbn
		// [ShipStatus] => 21
		// [ShipStatusDesc] => 已產宅配單號
		// [ShipStatusTime] => 2023-12-01 14:14:45
		// [MerID] => S12345678
		// [ApiType] => ShipStatus
		// [Status] => SUCCESS
		// [Message] => (測試環境)貨態狀態處理成功(21)

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
						$order->update_meta_data( OrderMeta::ShipNo, $decrypted_info['OBTNumber'] ); // OBTNumber = odno.
						$order->update_meta_data( OrderMeta::ShipStatus, $decrypted_info['ShipStatus'] );
						$order->update_meta_data( OrderMeta::ShipStatusDesc, $decrypted_info['ShipStatusDesc'] );
						$order->update_meta_data( OrderMeta::ShipStatusTime, $decrypted_info['ShipStatusTime'] );
					}

					if ( array_key_exists( 'FileNo', $decrypted_info ) ) {
						$order->update_meta_data( OrderMeta::FileNo, $decrypted_info['FileNo'] );
					}
					$order->save();

					if ( array_key_exists( 'ShipStatus', $decrypted_info ) ) {

						$shipping_log = _x( 'PAYUNi Shipping Notify', 'Shipping Note', 'mo-ectools' );
						$shiptrade_no = _x( 'ShipTradeNo: ', 'Shipping Note', 'mo-ectools' );
						$ship_status  = _x( 'ShipStatus: ', 'Shipping Note', 'mo-ectools' );
						$ship_desc    = _x( 'ShipStatusDesc: ', 'Shipping Note', 'mo-ectools' );
						$ship_time    = _x( 'ShipStatusTime: ', 'Shipping Note', 'mo-ectools' );

						$order->add_order_note( "<strong>{$shipping_log}</strong><br>{$shiptrade_no}{$decrypted_info['ShipTradeNo']}<br>{$ship_status}{$decrypted_info['ShipStatus']}<br>{$ship_desc}{$decrypted_info['ShipStatusDesc']}<br>{$ship_time}{$decrypted_info['ShipStatusTime']}" );

						// change order status based on ShipStatus.
						do_action( 'payuni_update_shipping_order_status', $order, $decrypted_info['ShipStatus'], $decrypted_info['ShipStatusDesc'] );
					}
				} else {
					PayuniShipping::log( 'PAYUNi NotifyURL response fail: can not find order by ShipTradeNo:' . $decrypted_info['ShipTradeNo'] );
				}
			} elseif ( 'Print' === $decrypted_info['ApiType'] ) {
				//https://docs.payuni.com.tw/web/#/7/269
				// (
				// 	[Status] => SUCCESS
				// 	[Message] => (模擬)宅配單取得宅配編號成功
				// 	[MerID] => S04061198
				// 	[LgsType] => HOME
				// 	[GoodsType] => 1
				// 	[ShipType] => 2
				// 	[ApiType] => Print
				// 	[JsonData] => [{"Status":"SUCCESS","Message":"(\u6a21\u64ec)\u5b85\u914d\u8a17\u904b\u55ae\u6210\u529f\u53d6\u865f","ShipTradeNo":"SC174470089200749526","Odno":"140004154806","FileNo":"NdaxzJxsFFd0nb1L55Bj0cQZDpFw8BEc","PrintDate":"2025-04-15 15:08:22"}]
				// )
				$json_data = json_decode( $decrypted_info['JsonData'], true );
				$print_result = $json_data[0];
				if ( 'SUCCESS' === $print_result['Status'] ) {
					$ship_trade_no = $print_result['ShipTradeNo'];
					$orders = wc_get_orders(
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

						$shipping_log   = _x( 'PAYUNi Shipping Print Notify', 'Shipping Note', 'mo-ectools' );
						$print_status   = _x( 'Print Status: ', 'Shipping Note', 'mo-ectools' );
						$print_message  = _x( 'Message: ', 'Shipping Note', 'mo-ectools' );

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
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public function update_order_status_after_received_update( \WC_Order $order, $ship_status, $ship_status_desc ) {

		PayuniShipping::log( 'Update order stauts. Order id:' . $order->get_id() . ', ship_status:' . $ship_status );

		if ( ShippingStatus::AT_LOGISTIC_CENTER === $ship_status && strpos( $ship_status_desc, 'EIN00' ) !== false && $order->get_meta( OrderMeta::LgsType ) == LgsType::B2C ) {
			if ( ! empty( PayuniShipping::$order_status_at_logistic_center ) ) {
					$order->update_status( PayuniShipping::$order_status_at_logistic_center );
			}
		} elseif ( ShippingStatus::AT_SENDER_CVS === $ship_status && strpos( $ship_status_desc, 'AOL') !== false && $order->get_meta( OrderMeta::LgsType ) == LgsType::C2C ) {
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
