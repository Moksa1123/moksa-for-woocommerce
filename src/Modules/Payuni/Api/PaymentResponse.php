<?php

namespace MoksaWeb\Mowc\Modules\Payuni\Api;

use MoksaWeb\Mowc\Modules\Payuni\Credentials;
use MoksaWeb\Mowc\Modules\Payuni\PayuniPayment;
use MoksaWeb\Mowc\Modules\Payuni\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\Payuni\Utils\PayType;
use MoksaWeb\Mowc\Modules\Payuni\Utils\SingletonTrait;
use MoksaWeb\Mowc\Modules\Payuni\Utils\TradeStatus;

defined( 'ABSPATH' ) || exit;

class PaymentResponse {

	use SingletonTrait;

	public static function init() {
		self::get_instance();
		add_action( 'woocommerce_api_payuni_payment', array( self::get_instance(), 'payuni_receive_notify' ), 10 );
		add_action( 'woocommerce_api_payuni_return', array( self::get_instance(), 'payuni_receive_response_frontend' ), 20 );
	}

	public static function payuni_receive_notify() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gateway webhook; HashInfo / EncryptInfo signature verified inside this method; wc_clean is WC's sanitize alias.
        // phpcs:disable WordPress.Security.NonceVerification.Missing

		if ( empty( $_POST ) ) {
			return;
		}

		$mer_id        = Credentials::merchant_id();
		$posted_mer_id = ( isset( $_POST['MerID'] ) ) ? wc_clean( wp_unslash( $_POST['MerID'] ) ) : '';

		if ( $mer_id !== $posted_mer_id ) {
			PayuniPayment::log( 'PAYUNi received response MerID not found or not match. ' );
			return;
		}

		$encrypt_info = ( isset( $_POST['EncryptInfo'] ) ) ? wc_clean( wp_unslash( $_POST['EncryptInfo'] ) ) : '';
		$posted_hash  = ( isset( $_POST['HashInfo'] ) ) ? wc_clean( wp_unslash( $_POST['HashInfo'] ) ) : '';

		// 必須在 decrypt 前驗 HashInfo — 否則被偽造的 EncryptInfo 會 silent fail at parse_str
		if ( '' === $posted_hash || ! hash_equals( PayuniPayment::hash_info( $encrypt_info ), strtoupper( $posted_hash ) ) ) {
			PayuniPayment::log( 'PAYUNi notify HashInfo mismatch — request rejected.' );
			status_header( 403 );
			return;
		}

		$decrypted_info = PayuniPayment::decrypt( $encrypt_info );
		PayuniPayment::log( 'PAYUNi NotifyURL response decrypted:' . wc_print_r( PaymentRequest::redact_for_log( $decrypted_info ), true ) );

		$status          = $decrypted_info['Status'];
		$payuni_order_no = $decrypted_info['MerTradeNo'];
		$message         = $decrypted_info['Message'];
		$pay_type        = $decrypted_info['PaymentType'];
		$trade_no        = $decrypted_info['TradeNo'];

		$text_log        = __( 'PAYUNi Notify', 'mo-ectools' );
		$text_code       = __( 'Status code:', 'mo-ectools' );
		$text_message    = __( 'Transaction message:', 'mo-ectools' );
		$text_mertradeno = __( 'MerTradeNo:', 'mo-ectools' );
		$text_number     = __( 'UNi number:', 'mo-ectools' );
		$text_paytype    = __( 'Payment type:', 'mo-ectools' );

		$woo_order_id = PayuniPayment::parse_payuni_order_no_to_woo_order_id( $payuni_order_no );
		$order        = wc_get_order( $woo_order_id );
		if ( ! $order ) {
			PayuniPayment::log( 'Cant find order by id:' . $woo_order_id );
			return;
		}

		// 電子發票的通知
		if ( array_key_exists( 'InvoiceNo', $decrypted_info ) ) {
			self::save_einvoice_data( $order, $decrypted_info );
			$order->add_order_note( 'PAYUNi E-Invoice Notify. InvoiceStatus:' . $decrypted_info['InvoiceStatus'] . ', InvoiceNo:' . $decrypted_info['InvoiceNo'] );
			return;
		}

		if ( $order->is_paid() || $order->get_meta( OrderMeta::TRADE_STATUS ) === TradeStatus::PAID ) {
			// PAYUNi 失敗會 retry / 成功後可能重送 — 已付款就不再灌重複 note，只記 log。
			PayuniPayment::log( sprintf( 'PAYUNi Notify: Order %s already paid or transaction status has already set as success. Skip duplicate note.', $woo_order_id ) );
		} else {
			$order->add_order_note( "<strong>{$text_log}</strong><br>{$text_code} {$status}<br>{$text_message} {$message}<br>{$text_mertradeno} {$payuni_order_no}<br>{$text_number} {$trade_no}<br>{$text_paytype} " . PayType::get_name( $pay_type ) );
			self::update_order_meta_and_order_status( $order, $decrypted_info );
		}

		// PAYUNi 失敗時會 retry，先 200 回避免重複 note
		status_header( 200 );
		echo 'OK';
		exit;
     // phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public static function payuni_receive_response_frontend() {
     // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gateway webhook; HashInfo / EncryptInfo signature verified inside this method; wc_clean is WC's sanitize alias.
     // phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST ) ) {
			return;
		}

		$mer_id        = Credentials::merchant_id();
		$posted_mer_id = ( isset( $_POST['MerID'] ) ) ? wc_clean( wp_unslash( $_POST['MerID'] ) ) : '';

		if ( $mer_id !== $posted_mer_id ) {
			PayuniPayment::log( 'PAYUNi received response MerID not found or not match. ' );
			return;
		}

		$encrypt_info = ( isset( $_POST['EncryptInfo'] ) ) ? wc_clean( wp_unslash( $_POST['EncryptInfo'] ) ) : '';
		$posted_hash  = ( isset( $_POST['HashInfo'] ) ) ? wc_clean( wp_unslash( $_POST['HashInfo'] ) ) : '';

		// 同 NotifyURL — 偽造 ReturnURL POST 會 driver payment_complete on unpaid order
		if ( '' === $posted_hash || ! hash_equals( PayuniPayment::hash_info( $encrypt_info ), strtoupper( $posted_hash ) ) ) {
			PayuniPayment::log( 'PAYUNi return HashInfo mismatch — request rejected.' );
			status_header( 403 );
			return;
		}

		$decrypted_info = PayuniPayment::decrypt( $encrypt_info );
		PayuniPayment::log( 'PAYUNi ReturnURL response decrypted:' . wc_print_r( PaymentRequest::redact_for_log( $decrypted_info ), true ) );

		$status   = $decrypted_info['Status'];
		$order_id = $decrypted_info['MerTradeNo'];
		$message  = $decrypted_info['Message'];
		$pay_type = $decrypted_info['PaymentType'];
		$trade_no = $decrypted_info['TradeNo'];
		
		$text_log        = __( 'PAYUNi Return', 'mo-ectools' );
		$text_code       = __( 'Status code:', 'mo-ectools' );
		$text_message    = __( 'Transaction message:', 'mo-ectools' );
		$text_mertradeno = __( 'MerTradeNo:', 'mo-ectools' );
		$text_number     = __( 'UNi number:', 'mo-ectools' );
		$text_paytype    = __( 'Payment type:', 'mo-ectools' );

		$woo_order_id = PayuniPayment::parse_payuni_order_no_to_woo_order_id( $order_id );
		$order        = wc_get_order( $woo_order_id );
		if ( ! $order ) {
			PayuniPayment::log( 'Cant find order by id:' . $woo_order_id );
			return;
		}

		if ( $order->is_paid() || $order->get_meta( OrderMeta::TRADE_STATUS ) === TradeStatus::PAID ) {
			// 已付款就不再灌重複 note，只記 log。
			PayuniPayment::log( sprintf( 'PAYUNi Return: Order %s already paid or transaction status has already set as success. Skip duplicate note.', $woo_order_id ) );
		} else {
			$order->add_order_note( "<strong>{$text_log}</strong><br>{$text_code} {$status}<br>{$text_message} {$message}<br>{$text_mertradeno} {$order_id}<br>{$text_number} {$trade_no}<br>{$text_paytype} " . PayType::get_name( $pay_type ) );
			self::update_order_meta_and_order_status( $order, $decrypted_info );
		}

		wp_safe_redirect( $order->get_checkout_order_received_url() );// 訂單感謝頁面.
		exit;

     // phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private static function save_payuni_order_data( $order, $decrypted_info ) {

		$pay_type = $decrypted_info['PaymentType'];

		$order->update_meta_data( OrderMeta::STATUS, $decrypted_info['Status'] );
		$order->update_meta_data( OrderMeta::MESSAGE, $decrypted_info['Message'] );
		$order->update_meta_data( OrderMeta::PAYUNI_ORDER_NO, $decrypted_info['MerTradeNo'] );
		$order->update_meta_data( OrderMeta::UNI_NO, $decrypted_info['TradeNo'] );
		$order->update_meta_data( OrderMeta::TRADE_STATUS, $decrypted_info['TradeStatus'] );
		$order->update_meta_data( OrderMeta::TRADE_AMOUNT, $decrypted_info['TradeAmt'] );
		$order->update_meta_data( OrderMeta::PAY_TYPE, $pay_type );

		self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_REST_CODE, 'ResCode' );
		self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_REST_CODE_MSG, 'ResCodeMsg' );
		self::update_order_meta( $order, $decrypted_info, OrderMeta::PAID_AT, 'TradeFinishTime' );

		if ( '1' === $pay_type ) {
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_AUTH_TYPE, 'AuthType' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_CARD_4NO, 'Card4No' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_AUTH_DAY, 'AuthDay' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_AUTH_TIME, 'AuthTime' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_AUTH_CODE, 'AuthCode' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_BANK, 'CardBank' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_LOCATION, 'LocationCard' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_ECI, 'ECI' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_RED_AMT, 'RedAmt' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_RED_NO, 'RedNo' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_TOKEN_ID, 'TokenID' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_TOKEN_LIFE, 'TokenLife' );

			if ( '2' === ( $decrypted_info['AuthType'] ?? '' ) ) {
				self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_INSTALL, 'CardInst' );
				self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_FIRST_AMT, 'FirstAmt' );
				self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_EACH_AMT, 'EachAmt' );
			}
		} elseif ( '2' === $pay_type ) {
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AMT_PAY_NO, 'PayNo' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AMT_BANK_TYPE, 'BankType' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AMT_PAY_TIME, 'PayTime' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AMT_ACCOUNT_5NO, 'Account5No' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AMT_PAY_SET, 'PaySet' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AMT_EXPIRE_DATE, 'ExpireDate' );
		} elseif ( '3' === $pay_type ) {
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CVS_PAY_NO, 'PayNo' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CVS_STORE, 'Store' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CVS_EXPIRE_DATE, 'ExpireDate' );
		} elseif ( '7' === $pay_type ) {
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AFTEE_PAY_NO, 'PayNo' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AFTEE_PAY_TIME, 'PayTime' );
		} elseif ( '9' === $pay_type ) {
			self::update_order_meta( $order, $decrypted_info, OrderMeta::LINE_PAY_NO, 'PayNo' );
		}

		self::update_order_meta( $order, $decrypted_info, OrderMeta::CLOSE_STATUS, 'CloseStatus' );
		self::update_order_meta( $order, $decrypted_info, OrderMeta::CLOSE_TIME, 'CloseTime' );
		self::update_order_meta( $order, $decrypted_info, OrderMeta::CLOSE_AUTH, 'BankSettleAuth' );

		self::update_order_meta( $order, $decrypted_info, OrderMeta::REFUND_NO, 'RefundNo' );
		self::update_order_meta( $order, $decrypted_info, OrderMeta::REFUND_AMT, 'RefundAmt' );
		self::update_order_meta( $order, $decrypted_info, OrderMeta::REFUND_TIME, 'RefundTime' );

		$order->update_meta_data( OrderMeta::PLUGIN_VERSION, MOWC_VERSION );

		$order->save();
	}

	private static function update_order_meta_and_order_status( $order, $decrypted_info ) {
		$trade_status = $decrypted_info['TradeStatus'];

		self::save_payuni_order_data( $order, $decrypted_info );

		if ( TradeStatus::PAID === $trade_status ) {
			$order->payment_complete( $decrypted_info['TradeNo'] );
		} elseif (
			TradeStatus::EXPIRED === $trade_status
			|| TradeStatus::CANCEL === $trade_status
			|| TradeStatus::FAIL === $trade_status
		) {
			$order->update_status( 'failed' );
		}
	}

	private static function save_einvoice_data( $order, $decrypted_info ) {
		$order->update_meta_data( OrderMeta::EINVOICE_NO, $decrypted_info['InvoiceNo'] );
		$order->update_meta_data( OrderMeta::EINVOICE_AMT, $decrypted_info['TradeAmt'] );
		$order->update_meta_data( OrderMeta::EINVOICE_TIME, $decrypted_info['InvoiceTime'] );
		$order->update_meta_data( OrderMeta::EINVOICE_TYPE, $decrypted_info['InvoiceNotifyType'] );
		$order->update_meta_data( OrderMeta::EINVOICE_INFO, $decrypted_info['InvoiceInfo'] );
		$order->update_meta_data( OrderMeta::EINVOICE_STATUS, $decrypted_info['InvoiceStatus'] );
		$order->save();
	}

	private static function update_order_meta( $order, $data, $meta_key, $data_key ) {
		if ( ! empty( $data[ $data_key ] ) ) {
			$value = ( 'Store' === $data_key && 'SEVEN' === $data[ $data_key ] ) ? '7-11' : $data[ $data_key ];
			$order->update_meta_data( $meta_key, $value );
		}
	}
}
