<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Smilepay\Api;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public const ROTURL_OK = 'woook1.1.23'; // SmilePay expects this token in a successful Roturl response body.

	public static function handle_roturl(): void {
		// SmilePay Roturl webhook: no WP nonce possible (external server cannot send one).
		// All fields are sanitized at extraction below. Source authenticity verified via Mid_smilepay
		// (hash_equals in self::verify_mid, line ~41) before any order state change.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- SmilePay Roturl IPN; no WP nonce possible; all fields sanitized at capture; Mid_smilepay hash_equals verified before any state change.
		$req = wp_unslash( $_REQUEST );

		$classif      = isset( $req['Classif'] ) ? sanitize_text_field( (string) $req['Classif'] ) : '';
		$order_id     = isset( $req['Data_id'] ) ? absint( $req['Data_id'] ) : 0;
		$mid_smilepay = isset( $req['Mid_smilepay'] ) ? sanitize_text_field( (string) $req['Mid_smilepay'] ) : '';
		$amount       = isset( $req['Amount'] ) ? sanitize_text_field( (string) $req['Amount'] ) : '';
		$smseid       = isset( $req['Smseid'] ) ? sanitize_text_field( (string) $req['Smseid'] ) : '';
		$process_date = isset( $req['Process_date'] ) ? sanitize_text_field( (string) $req['Process_date'] ) : '';
		$process_time = isset( $req['Process_time'] ) ? sanitize_text_field( Helper::big5_to_utf8( (string) $req['Process_time'] ) ) : '';

		if ( '' === $classif ) {
			self::die_status( __( 'No Classif value', 'moksa-for-woocommerce' ) );
		}
		if ( '' === $amount || false !== strpos( $amount, '.' ) ) {
			self::die_status( __( 'Unexpected amount', 'moksa-for-woocommerce' ) );
		}
		if ( '0' === $amount ) {
			self::die_status( __( 'Not paid, or the amount is 0', 'moksa-for-woocommerce' ) );
		}

		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			self::die_status( __( 'No such order', 'moksa-for-woocommerce' ) );
		}

		if ( ! self::order_uses_smilepay( $order ) ) {
			Helper::log( 'roturl rejected — order not paid via SmilePay', [ 'order_id' => $order_id ] );
			self::die_status( __( 'The order\'s payment method does not match', 'moksa-for-woocommerce' ) );
		}

		if ( ! self::verify_mid( $order, $amount, $smseid, $mid_smilepay ) ) {
			Helper::log( 'roturl Mid_smilepay mismatch', [ 'order_id' => $order_id ] );
			self::die_status( __( 'Mid_smilepay does not match', 'moksa-for-woocommerce' ) );
		}

		if ( ! self::amount_matches( $order, $amount ) ) {
			Helper::log(
				'roturl amount mismatch',
				[
					'order_id'    => $order_id,
					'reported'    => $amount,
					'order_total' => (string) (int) ceil( (float) $order->get_total() ),
				]
			);
			$order->add_order_note(
				sprintf(
				/* translators: 1: reported amount, 2: order total */
					__( 'SmilePay reported %1$s, which does not match the order total of %2$s. The order was marked as failed for you to check.', 'moksa-for-woocommerce' ),
					$amount,
					(string) (int) ceil( (float) $order->get_total() )
				)
			);
			$order->update_status( 'failed' );
			$order->save();
			self::die_status( __( 'Amount mismatch', 'moksa-for-woocommerce' ) );
		}

		$state = $order->get_status();
		if ( in_array( $state, [ 'processing', 'completed' ], true ) ) {
			self::die_ok();
		}

		$order->update_meta_data( Keys::SMILEPAY_PAY_SMILEPAY_NO, $smseid );
		$order->update_meta_data( Keys::SMILEPAY_PAY_AMOUNT, $amount );
		$order->update_meta_data( Keys::SMILEPAY_PAY_PAID_AT, trim( $process_date . ' ' . $process_time ) );
		$order->add_order_note(
			sprintf(
			/* translators: 1: date, 2: time */
				__( 'SmilePay received the payment (%1$s %2$s)', 'moksa-for-woocommerce' ),
				$process_date,
				$process_time
			)
		);

		if ( 'T' === $classif || 'O' === $classif ) {
			$order->payment_complete( $smseid );
		} else {
			$order->update_status( 'processing', __( 'The SmilePay payment code has been settled.', 'moksa-for-woocommerce' ) );
		}
		$order->save();

		\Moksafowo\Modules\Shared\Email\PaymentInfoEmailDispatcher::maybe_dispatch( $order );

		self::die_ok();
	}

	public static function handle_credit_roturl(): void {
		// SmilePay credit Roturl webhook: no WP nonce possible (external server cannot send one).
		// All fields sanitized at extraction; Mid_smilepay hash_equals (self::verify_mid) verified before any state change.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- SmilePay credit Roturl IPN; no WP nonce possible; all fields sanitized at capture; Mid_smilepay hash_equals verified before any state change.
		$req = wp_unslash( $_REQUEST );

		$classif       = isset( $req['Classif'] ) ? sanitize_text_field( (string) $req['Classif'] ) : '';
		$response_id   = isset( $req['Response_id'] ) ? sanitize_text_field( (string) $req['Response_id'] ) : '';
		$order_id      = isset( $req['Data_id'] ) ? absint( $req['Data_id'] ) : 0;
		$smseid        = isset( $req['Smseid'] ) ? sanitize_text_field( (string) $req['Smseid'] ) : '';
		$amount        = isset( $req['Amount'] ) ? sanitize_text_field( (string) $req['Amount'] ) : '';
		$date          = isset( $req['Process_date'] ) ? sanitize_text_field( (string) $req['Process_date'] ) : '';
		$time          = isset( $req['Process_time'] ) ? sanitize_text_field( Helper::big5_to_utf8( (string) $req['Process_time'] ) ) : '';
		$payment_title = isset( $req['Payment_title'] ) ? sanitize_text_field( Helper::big5_to_utf8( (string) $req['Payment_title'] ) ) : '';
		$err_desc      = isset( $req['Errdesc'] ) ? sanitize_text_field( Helper::big5_to_utf8( (string) $req['Errdesc'] ) ) : '';
		$mid_smilepay  = isset( $req['Mid_smilepay'] ) ? sanitize_text_field( (string) $req['Mid_smilepay'] ) : '';

		if ( '' === $classif && '' === $response_id ) {
			self::die_status( __( 'Missing parameters', 'moksa-for-woocommerce' ) );
		}

		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			self::die_status( __( 'No such order', 'moksa-for-woocommerce' ) );
		}

		if ( ! self::order_uses_smilepay( $order ) ) {
			Helper::log( 'credit_roturl rejected — order not paid via SmilePay', [ 'order_id' => $order_id ] );
			self::die_status( __( 'The order\'s payment method does not match', 'moksa-for-woocommerce' ) );
		}

		if ( ! self::verify_mid( $order, $amount, $smseid, $mid_smilepay ) ) {
			Helper::log( 'credit_roturl Mid_smilepay mismatch', [ 'order_id' => $order_id ] );
			self::die_status( __( 'Mid_smilepay does not match', 'moksa-for-woocommerce' ) );
		}

		$order->update_meta_data( Keys::SMILEPAY_PAY_SMILEPAY_NO, $smseid );

		if ( 'A' === $classif && '1' === $response_id ) {
			if ( ! self::amount_matches( $order, $amount ) ) {
				Helper::log(
					'credit_roturl amount mismatch',
					[
						'order_id' => $order_id,
						'reported' => $amount,
					]
				);
				$order->add_order_note(
					sprintf(
					/* translators: 1: reported amount, 2: order total */
						__( 'SmilePay authorized %1$s, which does not match the order total of %2$s. Please contact the store.', 'moksa-for-woocommerce' ),
						$amount,
						(string) (int) ceil( (float) $order->get_total() )
					)
				);
				$order->update_status( 'failed' );
				$order->update_meta_data( Keys::SMILEPAY_PAY_INFO_HTML, esc_html__( 'There is a problem with the order total. Please contact the store or place the order again.', 'moksa-for-woocommerce' ) );
				$order->save();
				self::redirect_to_received( $order );
			}

			$info = sprintf(
				'<div><p>%1$s</p><p>%2$s</p><p>%3$s</p></div>',
				esc_html( sprintf( /* translators: %s: pay method */ __( 'Payment method: %s', 'moksa-for-woocommerce' ), $payment_title ) ),
				esc_html( sprintf( /* translators: %s: amount */ __( 'Authorized amount: %s', 'moksa-for-woocommerce' ), $amount ) ),
				esc_html( sprintf( /* translators: %s: datetime */ __( 'Transaction time: %s', 'moksa-for-woocommerce' ), trim( $date . ' ' . $time ) ) )
			);
			$order->update_meta_data( Keys::SMILEPAY_PAY_AMOUNT, $amount );
			$order->update_meta_data( Keys::SMILEPAY_PAY_INFO_HTML, $info );
			$order->update_meta_data( Keys::SMILEPAY_PAY_PAID_AT, trim( $date . ' ' . $time ) );
			$order->add_order_note(
				sprintf(
				/* translators: %s: pay method */
					__( 'SmilePay authorized the card payment (%s)', 'moksa-for-woocommerce' ),
					$payment_title
				)
			);
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $smseid );
			}
		} else {
			$info = sprintf(
				'<div><p>%1$s</p><p>%2$s</p></div>',
				esc_html( sprintf( /* translators: %s: pay method */ __( 'Payment method: %s', 'moksa-for-woocommerce' ), $payment_title ) ),
				esc_html( sprintf( /* translators: %s: reason */ __( 'Authorization failed: %s', 'moksa-for-woocommerce' ), $err_desc ) )
			);
			$order->update_meta_data( Keys::SMILEPAY_PAY_INFO_HTML, $info );
			$order->add_order_note(
				sprintf(
				/* translators: %s: reason */
					__( 'SmilePay could not authorize the card payment: %s', 'moksa-for-woocommerce' ),
					$err_desc
				)
			);
			$order->update_status( 'failed' );
		}

		$order->save();
		self::redirect_to_received( $order );
	}

	private static function verify_mid( \WC_Order $order, string $amount, string $smseid, string $mid_smilepay ): bool {
		$mid = Helper::mid();
		if ( '' === $mid ) {
			// Fail closed:未設定參數碼就無法驗證回呼來源,一律拒絕(商家須在設定頁填入
			// 與 SmilePay 後台一致的參數碼,否則收不到入帳通知)。
			Helper::log( 'roturl rejected — merchant Mid (參數碼) not configured; cannot verify callback origin' );
			return false;
		}
		$expected = Helper::calc_mid_smilepay( $mid, $amount, $smseid );
		return hash_equals( $expected, $mid_smilepay );
	}

	/** 訂單必須是本模組的 SmilePay 付款方式,才接受 SmilePay 回呼改它的狀態。 */
	private static function order_uses_smilepay( \WC_Order $order ): bool {
		return 0 === strpos( (string) $order->get_payment_method(), 'moksafowo_smilepay_' );
	}

	private static function amount_matches( \WC_Order $order, string $amount ): bool {
		if ( ! ctype_digit( $amount ) ) {
			return false;
		}
		return (int) $amount === (int) ceil( (float) $order->get_total() );
	}

	private static function redirect_to_received( \WC_Order $order ): void {
		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	private static function die_ok(): void {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<Roturlstatus>' . esc_html( self::ROTURL_OK ) . '</Roturlstatus>';
		exit;
	}

	private static function die_status( string $reason ): void {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<Roturlstatus>' . esc_html( $reason ) . '</Roturlstatus>';
		exit;
	}
}
