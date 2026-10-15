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
		// 從 wpbr-payuni-shipping 換過來的商店，PAYUNi 後台的貨態通知網址多半還填著舊的端點；
		// 那個外掛停用後就沒人接，新訂單一則貨態都收不到。舊外掛沒啟用時由這裡接手（同樣先驗 HashInfo，
		// 只會找到本外掛建立的物流單）。
		if ( ! has_action( 'woocommerce_api_payuni_shipping_711_notify' ) ) {
			add_action( 'woocommerce_api_payuni_shipping_711_notify', array( self::get_instance(), 'moksafowo_payuni_711_receive_update' ) );
		}
		if ( ! has_action( 'woocommerce_api_payuni_shipping_tcat_notify' ) ) {
			add_action( 'woocommerce_api_payuni_shipping_tcat_notify', array( self::get_instance(), 'moksafowo_payuni_tcat_receive_update' ) );
		}
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
				$order = self::find_order_by_ship_trade_no( (string) ( $decrypted_info['ShipTradeNo'] ?? '' ) );

				if ( $order instanceof \WC_Order ) {

					if ( array_key_exists( 'ShipStatus', $decrypted_info ) ) {
						$order->update_meta_data( OrderMeta::ShipStatus, $decrypted_info['ShipStatus'] );
						$order->update_meta_data( OrderMeta::ShipStatusDesc, $decrypted_info['ShipStatusDesc'] );
						$order->update_meta_data( OrderMeta::ShipStatusTime, $decrypted_info['ShipStatusTime'] );
						self::update_record(
							$order,
							(string) $decrypted_info['ShipTradeNo'],
							[
								'ship_status'      => (string) $decrypted_info['ShipStatus'],
								'ship_status_desc' => (string) ( $decrypted_info['ShipStatusDesc'] ?? '' ),
								'ship_status_time' => (string) ( $decrypted_info['ShipStatusTime'] ?? '' ),
							]
						);
						$order->save();

						do_action( 'moksafowo_payuni_update_shipping_order_status', $order, $decrypted_info['ShipStatus'], $decrypted_info['ShipStatusDesc'] );
						// 狀態有變時 mapper 已寫一則備註；沒變才補這則。
						if ( ! \Moksafowo\Modules\Shipping\Webhook\AbstractStatusMapper::wrote_note( $order ) ) {
							$order->add_order_note( self::status_note( $decrypted_info ) );
						}
					}
				}
			} elseif ( 'Print' === $decrypted_info['ApiType'] ) {
				// 拆單時單一欄位只鏡射最後一包，其他包要從拆單紀錄找。
				$order = self::find_order_by_ship_trade_no( (string) $decrypted_info['ShipTradeNo'] );

				if ( $order instanceof \WC_Order ) {

					if ( array_key_exists( 'Odno', $decrypted_info ) ) {
						$validation_no = array_key_exists( 'ValidationNo', $decrypted_info ) ? $decrypted_info['ValidationNo'] : '';
						// 單一欄位只鏡射最新一包；拆單時其他包的號碼記在各自的紀錄裡。
						if ( $order->get_meta( OrderMeta::ShipTradeNo ) === $decrypted_info['ShipTradeNo'] ) {
							$order->update_meta_data( OrderMeta::PartnerId, $decrypted_info['PartnerId'] );
							$order->update_meta_data( OrderMeta::ShipNo, self::build_ship_no( $decrypted_info['LgsType'], $decrypted_info['PartnerId'], $decrypted_info['Odno'], $validation_no ) );
							$order->update_meta_data( OrderMeta::Odno, $decrypted_info['Odno'] );
							$order->update_meta_data( OrderMeta::ValidationNo, $validation_no );
						}
						self::update_record(
							$order,
							(string) $decrypted_info['ShipTradeNo'],
							[
								'odno'          => (string) $decrypted_info['Odno'],
								'partner_id'    => (string) ( $decrypted_info['PartnerId'] ?? '' ),
								'validation_no' => (string) $validation_no,
							]
						);
						$order->save();
					}

					$order->add_order_note(
						self::print_note( $decrypted_info )
					);

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
				$order = self::find_order_by_ship_trade_no( (string) ( $decrypted_info['ShipTradeNo'] ?? '' ) );

				if ( $order instanceof \WC_Order ) {

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

						do_action( 'moksafowo_payuni_update_shipping_order_status', $order, $decrypted_info['ShipStatus'], $decrypted_info['ShipStatusDesc'] );
						// 狀態有變時 mapper 已寫一則備註；沒變才補這則。
						if ( ! \Moksafowo\Modules\Shipping\Webhook\AbstractStatusMapper::wrote_note( $order ) ) {
							$order->add_order_note( self::status_note( $decrypted_info ) );
						}
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
					$ship_trade_no = (string) $print_result['ShipTradeNo'];
					$order         = self::find_order_by_ship_trade_no( $ship_trade_no );

					if ( $order instanceof \WC_Order ) {
						$order->update_meta_data( OrderMeta::FileNo, $print_result['FileNo'] );
						$order->update_meta_data( OrderMeta::PrintDate, $print_result['PrintDate'] );
						$order->save();

						$order->add_order_note(
							self::print_note( [ 'ShipTradeNo' => $ship_trade_no ] + $decrypted_info )
						);

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

	/**
	 * 依物流交易序號找訂單。拆單時單一欄位只存最後一包，其他包要從拆單紀錄找；
	 * 原本只比對單一欄位，第一包（含運費、代收主單）的通知全被丟掉。
	 */
	/**
	 * 貨態通知備註：「PAYUNi 貨態：門市已收件」＋交易編號、狀態碼、時間。
	 *
	 * @param array<string, mixed> $info 解密後的通知。
	 */
	private static function status_note( array $info ): string {
		$desc  = (string) ( $info['ShipStatusDesc'] ?? '' );
		$code  = (string) ( $info['ShipStatus'] ?? '' );
		$label = '' !== $desc ? $desc : $code;
		// 92 的說明是 GET-GETNO、AOL-AOL 這類代碼，商家看不懂；只有含 AOL 才是門市已收件。
		if ( '92' === $code ) {
			$label = str_contains( $desc, 'AOL' )
				? __( 'Received at the drop-off store', 'moksa-for-woocommerce' )
				: __( 'Waiting for the parcel to be dropped off', 'moksa-for-woocommerce' );
		}
		return \Moksafowo\Order\Note::build(
			/* translators: %s: shipping status description */
			sprintf( __( 'PAYUNi shipping status: %s', 'moksa-for-woocommerce' ), $label ),
			[
				__( 'Shipping transaction ID', 'moksa-for-woocommerce' ) => $info['ShipTradeNo'] ?? '',
				__( 'Status code', 'moksa-for-woocommerce' )             => '' !== $desc && $desc !== $label ? $code . ' / ' . $desc : $code,
				__( 'Status updated', 'moksa-for-woocommerce' )          => $info['ShipStatusTime'] ?? '',
			]
		);
	}

	/**
	 * 列印通知備註。
	 *
	 * @param array<string, mixed> $info 解密後的通知。
	 */
	private static function print_note( array $info ): string {
		return \Moksafowo\Order\Note::build(
			/* translators: %s: PAYUNi message */
			sprintf( __( 'PAYUNi label print notice: %s', 'moksa-for-woocommerce' ), (string) ( $info['Message'] ?? '' ) ),
			[
				__( 'Shipping transaction ID', 'moksa-for-woocommerce' ) => $info['ShipTradeNo'] ?? '',
				__( 'Status code', 'moksa-for-woocommerce' )             => $info['Status'] ?? '',
			]
		);
	}

	private static function find_order_by_ship_trade_no( string $no ): ?\WC_Order {
		if ( '' === $no ) {
			return null;
		}
		$ids = wc_get_orders(
			[
				'limit'      => 1,
				'return'     => 'ids',
				'type'       => 'shop_order',
				// 關掉自訂狀態區塊後，停在 moksa-* 的訂單不在預設 status 範圍內，通知會找不到單。
				'status'     => 'all',
				'meta_key'   => OrderMeta::ShipTradeNo, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- IPN order resolution.
				'meta_value' => $no, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- IPN order resolution.
			]
		);
		if ( empty( $ids ) ) {
			$ids = wc_get_orders(
				[
					'limit'        => 5,
					'return'       => 'ids',
					'type'         => 'shop_order',
					'status'       => 'all',
					'meta_key'     => \Moksafowo\Order\Meta\Keys::PAYUNI_SHIPPING_RECORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- IPN order resolution.
					'meta_value'   => $no, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- IPN order resolution.
					'meta_compare' => 'LIKE',
				]
			);
		}
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			if ( $order->get_meta( OrderMeta::ShipTradeNo ) === $no ) {
				return $order;
			}
			foreach ( \Moksafowo\Modules\PayuniShipping\Operations\CreateOrderUnified::get_records( $order ) as $r ) {
				if ( ( $r['ship_trade_no'] ?? '' ) === $no ) {
					return $order;
				}
			}
		}
		PayuniShipping::log( 'PAYUNi notify: no order for ShipTradeNo ' . $no );
		return null;
	}

	/** 把通知內容記進對應那一包的紀錄（沒有拆單紀錄的舊訂單略過）。 */
	private static function update_record( \WC_Order $order, string $no, array $fields ): void {
		$records = \Moksafowo\Modules\PayuniShipping\Operations\CreateOrderUnified::get_records( $order );
		if ( empty( $records ) ) {
			return;
		}
		foreach ( $records as &$r ) {
			if ( ( $r['ship_trade_no'] ?? '' ) === $no ) {
				$r = array_merge( $r, $fields );
				break;
			}
		}
		unset( $r );
		$order->update_meta_data( \Moksafowo\Order\Meta\Keys::PAYUNI_SHIPPING_RECORDS, $records );
	}

	public function update_order_status_after_received_update( \WC_Order $order, $ship_status, $ship_status_desc ) {

		PayuniShipping::log( 'Update order stauts. Order id:' . $order->get_id() . ', ship_status:' . $ship_status );

		if ( ShippingStatus::AT_LOGISTIC_CENTER === $ship_status && strpos( $ship_status_desc, 'EIN00' ) !== false && $order->get_meta( OrderMeta::LgsType ) === LgsType::B2C ) {
			if ( ! empty( PayuniShipping::$order_status_at_logistic_center ) && \Moksafowo\Modules\Shipping\Statuses\Registrar::is_usable( (string) PayuniShipping::$order_status_at_logistic_center ) ) {
					$order->update_status( PayuniShipping::$order_status_at_logistic_center );
			}
		} elseif ( ShippingStatus::AT_SENDER_CVS === $ship_status && strpos( $ship_status_desc, 'AOL' ) !== false && $order->get_meta( OrderMeta::LgsType ) === LgsType::C2C ) {
			if ( ! empty( PayuniShipping::$order_status_at_sender_cvs ) && \Moksafowo\Modules\Shipping\Statuses\Registrar::is_usable( (string) PayuniShipping::$order_status_at_sender_cvs ) ) {
				$order->update_status( PayuniShipping::$order_status_at_sender_cvs );
			}
		} elseif ( ShippingStatus::DELIVERING === $ship_status ) {
			if ( ! empty( PayuniShipping::$order_status_delivering ) && \Moksafowo\Modules\Shipping\Statuses\Registrar::is_usable( (string) PayuniShipping::$order_status_delivering ) ) {
				$order->update_status( PayuniShipping::$order_status_delivering );
			}
		} elseif ( ShippingStatus::AT_RECEIVER_CVS === $ship_status ) {
			if ( ! empty( PayuniShipping::$order_status_at_receiver_cvs ) && \Moksafowo\Modules\Shipping\Statuses\Registrar::is_usable( (string) PayuniShipping::$order_status_at_receiver_cvs ) ) {
				$order->update_status( PayuniShipping::$order_status_at_receiver_cvs );
			}
		} elseif ( ShippingStatus::CUSTOMER_PICKUP === $ship_status ) {
			if ( ! empty( PayuniShipping::$order_status_pickuped ) && \Moksafowo\Modules\Shipping\Statuses\Registrar::is_usable( (string) PayuniShipping::$order_status_pickuped ) ) {
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
