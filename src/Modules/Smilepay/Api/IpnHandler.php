<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Smilepay\Api;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public const ROTURL_OK = 'woook1.1.23'; // SmilePay expects this token in a successful Roturl response body.

	private const MAX_ORDER_FAILS = 3;
	private const MAX_SITE_FAILS  = 30;
	private const SITE_FAILS      = 'moksafowo_smilepay_mid_fails';
	// 有 key 的訂單驗證失敗另外計：不擋通知（避免被人打滿後擋掉正常入帳），只讓信用卡授權改為待核對。

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
			Helper::warn( 'roturl rejected — order not paid via SmilePay', [ 'order_id' => $order_id ] );
			self::die_status( __( 'The order\'s payment method does not match', 'moksa-for-woocommerce' ) );
		}

		if ( ! self::authentic( $order, $req, $smseid, $mid_smilepay, [ 'roturl' ] ) ) {
			self::die_status( __( 'Mid_smilepay does not match', 'moksa-for-woocommerce' ) );
		}
		if ( self::smseid_used_elsewhere( $order, $smseid ) ) {
			self::die_status( __( 'This SmilePay transaction belongs to another order', 'moksa-for-woocommerce' ) );
		}

		// 已入帳的訂單不再處理（晚到或重送的通知不能把它改成失敗）。
		if ( $order->is_paid() ) {
			self::die_ok();
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

		// 取號時速買配已回傳這筆的金流追蹤碼（SmilePayNO），入帳通知的 Smseid 是同一個追蹤碼。
		// 對不上就不自動入帳，交給商家到速買配後台核對（Mid_smilepay 只有約 73 種值，擋不住猜測）。
		$issued_no = (string) $order->get_meta( Keys::SMILEPAY_PAY_SMILEPAY_NO );
		if ( '' !== $issued_no && $issued_no !== $smseid ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: tracking number in the notice, 2: tracking number issued with the payment code */
					__( 'SmilePay sent a payment notice with tracking number %1$s, but the payment code for this order was issued with %2$s. The order was not marked as paid; please check it in the SmilePay back office.', 'moksa-for-woocommerce' ),
					$smseid,
					$issued_no
				)
			);
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

		if ( 'T' !== $classif && 'O' !== $classif ) {
			$order->add_order_note( __( 'The SmilePay payment code has been settled.', 'moksa-for-woocommerce' ) );
		}
		$order->payment_complete( $smseid );
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
			Helper::warn( 'credit_roturl rejected — order not paid via SmilePay', [ 'order_id' => $order_id ] );
			self::die_status( __( 'The order\'s payment method does not match', 'moksa-for-woocommerce' ) );
		}

		// 更新前已送出的信用卡單帶的是舊的（roturl）key，一併接受；反過來不行。
		if ( ! self::authentic( $order, $req, $smseid, $mid_smilepay, [ 'credit', 'roturl' ] ) ) {
			self::die_status( __( 'Mid_smilepay does not match', 'moksa-for-woocommerce' ) );
		}
		if ( self::smseid_used_elsewhere( $order, $smseid ) ) {
			self::die_status( __( 'This SmilePay transaction belongs to another order', 'moksa-for-woocommerce' ) );
		}
		// 新單的回呼網址不再帶 Payment_title（Roturl 上限 200 字元），改讀訂單上的付款方式名稱。
		if ( '' === $payment_title ) {
			$payment_title = $order->get_payment_method_title();
		}

		$order->update_meta_data( Keys::SMILEPAY_PAY_SMILEPAY_NO, $smseid );

		if ( 'A' === $classif && '1' === $response_id ) {
			if ( ! $order->is_paid() && ! self::amount_matches( $order, $amount ) ) {
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
			// 瀏覽器重新整理會重送同一個結果；已付款的單不再覆寫交易資料（後台核對、退款要看原本那筆）。
			if ( ! $order->is_paid() ) {
				$order->update_meta_data( Keys::SMILEPAY_PAY_AMOUNT, $amount );
				$order->update_meta_data( Keys::SMILEPAY_PAY_INFO_HTML, $info );
				$order->update_meta_data( Keys::SMILEPAY_PAY_PAID_AT, trim( $date . ' ' . $time ) );
				$order->set_transaction_id( $smseid );
				// 信用卡的回呼網址（含驗證 key）放在轉址到速買配的網址裡，顧客看得到；Mid_smilepay 又只是
				// 位數加權和，看過一次合法回傳就能自己算。速買配沒有查單 API 可回頭確認，所以這則通知
				// 無法證明真的授權成功：一律轉保留，請商家到速買配後台核對後再出貨。
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: %s: pay method */
						__( 'SmilePay reported that the card payment (%s) was authorized. SmilePay card results cannot be verified automatically, so confirm the authorization in the SmilePay back office, then change the order to Processing.', 'moksa-for-woocommerce' ),
						$payment_title
					)
				);
			}
		} elseif ( $order->is_paid() ) {
			// 已付款的單不因晚到或偽造的失敗通知被打回失敗，只留紀錄給商家核對。
			$order->add_order_note(
				sprintf(
					/* translators: %s: reason */
					__( 'SmilePay sent a failed-authorization notice for this already paid order. The order status was not changed; please check it in the SmilePay back office. Reason: %s', 'moksa-for-woocommerce' ),
					$err_desc
				)
			);
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

	/**
	 * 回呼是不是真的來自速買配。依序：訂單專屬 key → 失敗次數上限 → Mid_smilepay。
	 * Mid_smilepay 能猜的值很少，所以猜錯要計數、到上限就停止自動入帳。
	 *
	 * @param \WC_Order $order        訂單。
	 * @param array     $req          請求參數。
	 * @param string    $smseid       速買配金流追蹤碼。
	 * @param string    $mid_smilepay 通知帶的驗證碼。
	 * @param string[]  $endpoints    這個端點接受哪些 key（見 Helper::callback_key）。
	 */
	private static function authentic( \WC_Order $order, array $req, string $smseid, string $mid_smilepay, array $endpoints ): bool {
		$order_id = $order->get_id();

		// 更新前就送出的單，回呼網址沒有 key（例如已取號、幾天後才繳費的 ATM），只能沿用舊驗證。
		$keyed = 'yes' === $order->get_meta( Keys::SMILEPAY_CALLBACK_KEYED );
		if ( $keyed ) {
			$key    = isset( $req['mfk'] ) ? sanitize_text_field( (string) $req['mfk'] ) : '';
			$key_ok = false;
			foreach ( $endpoints as $endpoint ) {
				if ( '' !== $key && hash_equals( Helper::callback_key( $order, $endpoint ), $key ) ) {
					$key_ok = true;
				}
			}
			if ( ! $key_ok ) {
				Helper::warn( 'roturl rejected — callback key missing or wrong', [ 'order_id' => $order_id ] );
				return false;
			}
		}

		// 全站鎖只管沒有 key 的舊訂單：任何人都能拿舊單號亂送把它打滿，不能連帶擋掉已驗過 key 的通知。
		$fails       = (int) $order->get_meta( Keys::SMILEPAY_MID_FAILS );
		$site_locked = ! $keyed && (int) get_transient( self::SITE_FAILS ) >= self::MAX_SITE_FAILS;
		if ( $fails >= self::MAX_ORDER_FAILS || $site_locked ) {
			Helper::warn( 'roturl rejected — too many failed verifications', [ 'order_id' => $order_id ] );
			return false;
		}

		if ( self::verify_mid( $order, $smseid, $mid_smilepay ) ) {
			return true;
		}

		Helper::warn( 'roturl Mid_smilepay mismatch', [ 'order_id' => $order_id ] );
		if ( ! $keyed ) {
			set_transient( self::SITE_FAILS, (int) get_transient( self::SITE_FAILS ) + 1, HOUR_IN_SECONDS );
		}
		$order->update_meta_data( Keys::SMILEPAY_MID_FAILS, $fails + 1 );
		if ( $fails + 1 === self::MAX_ORDER_FAILS ) {
			$order->add_order_note( __( 'Several SmilePay payment notices for this order failed verification, so automatic payment confirmation has been stopped for it. Please check the payment in the SmilePay back office and update the order manually.', 'moksa-for-woocommerce' ) );
		}
		$order->save();
		return false;
	}

	private static function verify_mid( \WC_Order $order, string $smseid, string $mid_smilepay ): bool {
		$mid = Helper::mid();
		if ( '' === $mid ) {
			// Fail closed:未設定參數碼就無法驗證回呼來源,一律拒絕(商家須在設定頁填入
			// 與 SmilePay 後台一致的參數碼,否則收不到入帳通知)。
			Helper::warn( 'roturl rejected — merchant Mid (參數碼) not configured; cannot verify callback origin' );
			return false;
		}
		// 速買配不論成功失敗都以訂單金額計算（失敗通知的 Amount 是 0），也讓偽造者不能自選金額。
		$expected = Helper::calc_mid_smilepay( $mid, (string) (int) ceil( (float) $order->get_total() ), $smseid );
		return hash_equals( $expected, $mid_smilepay );
	}

	/** 同一筆速買配交易（Smseid）只能入帳到一張訂單。 */
	private static function smseid_used_elsewhere( \WC_Order $order, string $smseid ): bool {
		if ( '' === $smseid ) {
			return false;
		}
		$other = \Moksafowo\Order\Lookup::by_meta( Keys::SMILEPAY_PAY_SMILEPAY_NO, $smseid );
		if ( null !== $other && $other !== $order->get_id() ) {
			Helper::log(
				'roturl rejected — Smseid already used by another order',
				[
					'order_id' => $order->get_id(),
					'other'    => $other,
				]
			);
			return true;
		}
		return false;
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
