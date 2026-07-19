<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Api;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public static function handle(): void {
		// Gateway IPN: no WP nonce possible (external server cannot send one).
		// Source authenticity verified via CheckMacValue SHA256 + hash_equals on line ~25 (Helper::verify_check_mac_value)
		// before any field is read or state changed. Raw array passed unmodified to verifier; map_deep sanitize follows on line ~32.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- gateway IPN; no WP nonce possible; source verified via CheckMacValue hash_equals before any use; sanitized via map_deep below.
		$raw = $_POST;

		if ( empty( $raw ) ) {
			status_header( 400 );
			echo 'Empty';
			exit;
		}

		if ( ! Helper::verify_check_mac_value( $raw ) ) {
			Helper::log( 'IPN CheckMacValue mismatch — rejected' );
			status_header( 400 );
			echo 'CheckMacValue mismatch';
			exit;
		}

		$posted = array_map(
			static fn( $v ) => is_string( $v ) ? sanitize_text_field( wp_unslash( $v ) ) : $v,
			$raw
		);

		Helper::log( 'IPN received', [ 'data' => $posted ] );

		$merchant_trade_no = isset( $posted['MerchantTradeNo'] ) ? wc_clean( wp_unslash( $posted['MerchantTradeNo'] ) ) : '';
		$order_id          = Helper::parse_order_id_from_merchant_trade_no( $merchant_trade_no );

		if ( ! $order_id ) {
			Helper::log( 'IPN order_id not found', [ 'merchant_trade_no' => $merchant_trade_no ] );
			status_header( 404 );
			echo 'Order not found';
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			status_header( 404 );
			echo 'Order not loadable';
			exit;
		}

		$rtn_code     = (int) ( $posted['RtnCode'] ?? 0 );
		$payment_type = (string) ( $posted['PaymentType'] ?? '' );
		$trade_no     = (string) ( $posted['TradeNo'] ?? '' );

		$order->update_meta_data( Keys::ECPAY_TRADE_NO, $trade_no );
		$order->update_meta_data( Keys::ECPAY_MERCHANT_TRADE_NO, $merchant_trade_no );
		$order->update_meta_data( Keys::ECPAY_PAYMENT_TYPE, $payment_type );
		$order->update_meta_data( Keys::ECPAY_RTN_CODE, (string) $rtn_code );

		if ( ! empty( $posted['PaymentDate'] ) ) {
			$order->update_meta_data( Keys::ECPAY_PAYMENT_DATE, (string) $posted['PaymentDate'] );
		}
		if ( ! empty( $posted['card4no'] ) ) {
			$order->update_meta_data( Keys::ECPAY_CARD_LAST4, (string) $posted['card4no'] );
		}

		if ( in_array( $rtn_code, [ 1, 2, 10100073 ], true ) ) {
			if ( 1 === $rtn_code ) {
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $trade_no );
					$order->add_order_note(
						sprintf(
						/* translators: %s: 中文化的付款方式名稱 */
							__( '付款方式：%s', 'moksa-for-woocommerce' ),
							self::payment_type_label( (string) $payment_type )
						)
					);
				}
			} else {
				self::store_payment_info( $order, $posted );
				$note = sprintf(
					/* translators: 1: 中文化的付款方式名稱, 2: rtn code */
					__( '綠界取號完成（%1$s，等待付款。狀態代碼 %2$s）', 'moksa-for-woocommerce' ),
					self::payment_type_label( (string) $payment_type ),
					(string) $rtn_code
				);
				// 取號成功 → on-hold 等待顧客付款（與 SmilePay/Newebpay 一致）。
				// 留在 pending 會被 WC 「保留庫存逾時」自動取消，顧客付的卻是已取消單。
				if ( ! $order->is_paid() && 'on-hold' !== $order->get_status() ) {
					$order->update_status( 'on-hold', $note );
				} else {
					$order->add_order_note( $note );
				}
			}
		} else {
			$msg  = $posted['RtnMsg'] ?? '';
			$note = sprintf(
				/* translators: 1: rtn message, 2: rtn code */
				__( '綠界付款失敗：%1$s（狀態代碼 %2$s）', 'moksa-for-woocommerce' ),
				wc_clean( wp_unslash( (string) $msg ) ),
				(string) $rtn_code
			);
			if ( ! $order->is_paid() ) {
				$order->update_status( 'failed', $note );
			} else {
				$order->add_order_note( $note );
			}
		}

		$order->save();

		if ( in_array( $rtn_code, [ 2, 10100073 ], true ) && ! $order->get_meta( Keys::PAYMENT_INFO_EMAIL_SENT ) ) {
			$order->update_meta_data( Keys::PAYMENT_INFO_EMAIL_SENT, '1' );
			$order->save();
			do_action( 'moksafowo_payment_info_email', $order->get_id() );
		}

		echo '1|OK';
		exit;
	}


	private static function store_payment_info( \WC_Order $order, array $posted ): void {
		$get = static fn( string $k ): string => isset( $posted[ $k ] ) ? wc_clean( wp_unslash( (string) $posted[ $k ] ) ) : '';

		if ( '' !== $get( 'vAccount' ) ) {
			$order->update_meta_data( Keys::ECPAY_ATM_BANK_CODE, $get( 'BankCode' ) );
			$order->update_meta_data( Keys::ECPAY_ATM_V_ACCOUNT, $get( 'vAccount' ) );
			$order->update_meta_data( Keys::ECPAY_ATM_EXPIRE_DATE, $get( 'ExpireDate' ) );
		}
		if ( '' !== $get( 'PaymentNo' ) ) {
			$order->update_meta_data( Keys::ECPAY_CVS_PAYMENT_NO, $get( 'PaymentNo' ) );
			$order->update_meta_data( Keys::ECPAY_CVS_EXPIRE_DATE, $get( 'ExpireDate' ) );
		}
		if ( '' !== $get( 'Barcode1' ) ) {
			$order->update_meta_data( Keys::ECPAY_BARCODE_1, $get( 'Barcode1' ) );
			$order->update_meta_data( Keys::ECPAY_BARCODE_2, $get( 'Barcode2' ) );
			$order->update_meta_data( Keys::ECPAY_BARCODE_3, $get( 'Barcode3' ) );
			$order->update_meta_data( Keys::ECPAY_BARCODE_EXPIRE_DATE, $get( 'ExpireDate' ) );
		}
	}

	private static function payment_type_label( string $raw ): string {
		return \Moksafowo\Modules\Ecpay\PaymentTypeCatalog::label( $raw, $raw );
	}
}
