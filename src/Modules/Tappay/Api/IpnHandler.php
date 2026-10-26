<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Api;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public const PENDING_CHECK_HOOK = 'moksafowo_tappay_pending_check';


	public static function handle_notify(): void {
		// Signature must be computed over raw bytes before any parsing.
		$raw_body = file_get_contents( 'php://input' );
		if ( ! is_string( $raw_body ) || '' === $raw_body ) {
			Helper::log( 'notify empty body' );
			status_header( 400 );
			echo 'empty';
			exit;
		}

		$signature = self::header( 'X-Tappay-Signature' );
		if ( ! Helper::verify_notify_signature( $raw_body, $signature ) ) {
			Helper::warn( 'notify signature mismatch — rejected' );
			status_header( 401 );
			echo 'invalid signature';
			exit;
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			Helper::log( 'notify body not JSON' );
			status_header( 400 );
			echo 'bad request';
			exit;
		}

		$payload = map_deep( $payload, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );

		Helper::log( 'notify received', [ 'payload' => $payload ] );

		$order_number = (string) ( $payload['order_number'] ?? '' );
		$order        = self::resolve_order( $order_number );
		if ( ! $order instanceof \WC_Order ) {
			Helper::log( 'notify order not found', [ 'order_number' => $order_number ] );
			status_header( 404 );
			echo 'order not found';
			exit;
		}
		// 只處理用 TapPay 付款的訂單（沙箱模式的通知金鑰可能是公開的測試值）。
		if ( ! str_starts_with( (string) $order->get_payment_method(), 'moksafowo_tappay' ) ) {
			Helper::warn( 'notify rejected — order not paid via TapPay', [ 'order_id' => $order->get_id() ] );
			status_header( 400 );
			echo 'payment method mismatch';
			exit;
		}
		// 成功通知的金額要跟訂單一致。
		if ( 0 === (int) ( $payload['status'] ?? -1 ) && isset( $payload['amount'] ) && (int) $payload['amount'] !== (int) round( (float) $order->get_total() ) ) {
			$note = sprintf(
				/* translators: 1: paid amount, 2: order total */
				__( 'TapPay reported a payment of %1$s, which does not match the order total of %2$s. Please check the payment before fulfilling the order.', 'moksa-for-woocommerce' ),
				(int) $payload['amount'],
				$order->get_total()
			);
			// 已付款的訂單不被另一筆通知打回保留中，只留備註。
			if ( $order->is_paid() ) {
				$order->add_order_note( $note );
			} else {
				$order->update_status( 'on-hold', $note );
			}
			status_header( 200 );
			echo 'OK';
			exit;
		}

		// 成功通知的交易編號要是這張訂單送出的那一筆（規格建議比對 pay-by-prime 取得的 rec_trade_id）。
		$stored_trade = (string) $order->get_meta( Keys::TAPPAY_REC_TRADE_ID );
		if ( 0 === (int) ( $payload['status'] ?? -1 ) && ! $order->is_paid() && '' !== $stored_trade && $stored_trade !== (string) ( $payload['rec_trade_id'] ?? '' ) ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: transaction id in the notice, 2: transaction id stored on the order */
					__( 'TapPay sent a payment notice for transaction %1$s, but this order was charged as transaction %2$s. The order was not marked as paid; please check it in the TapPay portal.', 'moksa-for-woocommerce' ),
					(string) ( $payload['rec_trade_id'] ?? '' ),
					$stored_trade
				)
			);
			status_header( 200 );
			echo 'OK';
			exit;
		}

		// 已付款訂單收到另一筆交易的通知（非成功、或另一次也成功的扣款）：只留備註，不覆寫 rec_trade_id（退款要用付款成功那筆）。
		$other_trade = '' !== $stored_trade && $stored_trade !== (string) ( $payload['rec_trade_id'] ?? '' );
		if ( $order->is_paid() && ( 0 !== (int) ( $payload['status'] ?? -1 ) || $other_trade ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: TapPay status code */
					__( 'TapPay sent a notice (status code %s) for this already paid order. The order was not changed.', 'moksa-for-woocommerce' ),
					(string) ( $payload['status'] ?? '-' )
				)
			);
		} else {
			self::write_transaction_meta( $order, $payload );
			self::apply_status( $order, $payload );
		}
		$order->save();

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'OK';
		exit;
	}

	public static function handle_result(): void {
		// 3DS browser redirect: no WP nonce possible (TapPay redirects the customer browser back here).
		// These query params are used only to look up the order; authoritative payment status is fetched
		// server-side from TapPay API (Client::query_by_*) below — no direct trust placed in query string values.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 3DS browser return; no WP nonce possible; order_number/rec_trade_id used only for lookup; authoritative status fetched server-side from TapPay API below.
		$order_number = isset( $_GET['order_number'] ) ? sanitize_text_field( wp_unslash( $_GET['order_number'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same as above.
		$rec_trade_id = isset( $_GET['rec_trade_id'] ) ? sanitize_text_field( wp_unslash( $_GET['rec_trade_id'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- result token, verified below with hash_equals.
		$token = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';

		$order = self::resolve_order( $order_number );
		if ( ! $order instanceof \WC_Order || ! hash_equals( Helper::result_token( $order ), $token ) ) {
			// 驗證碼不符：不查詢、不導到含 order key 的網址。付款結果仍會由 notify 通知寫入。
			Helper::warn( '3DS result order not found or token invalid', [ 'order_number' => $order_number ] );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$record = self::query_verified_record( $order, $rec_trade_id );
		if ( null !== $record ) {
			self::write_transaction_meta( $order, $record );
			self::apply_status( $order, $record );
			$order->save();
		}

		// 待付款（例如銀行還在確認）：不要叫顧客重付，排補查，結果出來前先顯示訂單頁。
		if ( null !== $record && 4 === (int) ( $record['record_status'] ?? -1 ) ) {
			self::schedule_pending_check( $order );
			wc_add_notice( __( 'Your payment is still being confirmed. We will update the order as soon as TapPay confirms it, so there is no need to pay again.', 'moksa-for-woocommerce' ), 'notice' );
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		if ( $order->is_paid() || $order->has_status( [ 'processing', 'completed', 'on-hold' ] ) ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		wc_add_notice( __( 'The 3-D Secure check did not finish, or the payment failed. Please try again.', 'moksa-for-woocommerce' ), 'error' );
		wp_safe_redirect( $order->get_checkout_payment_url( false ) );
		exit;
	}

	/**
	 * 向 TapPay 查這張訂單的交易紀錄（Record API），而且只採用訂單號與金額都對得上的那筆。
	 * 返回頁與補查共用：前端跳轉的資料可能被改，狀態一律以伺服器查到的為準。
	 */
	private static function query_verified_record( \WC_Order $order, string $rec_trade_id = '' ): ?array {
		// 網址上的 rec_trade_id 任何人都能改成自己另一筆成功交易的編號；訂單已存的優先。
		$stored_trade_id = (string) $order->get_meta( Keys::TAPPAY_REC_TRADE_ID );
		if ( '' !== $stored_trade_id ) {
			$rec_trade_id = $stored_trade_id;
		}
		$expected_number = (string) $order->get_meta( Keys::TAPPAY_ORDER_NUMBER );
		if ( '' === $expected_number ) {
			$expected_number = Helper::build_order_number( $order );
		}

		// Always server-query to confirm status; do not trust query-string params.
		$query = '' !== $rec_trade_id
			? Client::query_by_rec_trade_id( $rec_trade_id )
			: Client::query_by_order_number( $expected_number );

		$record = self::first_trade_record( $query['data'] );
		// 查回來的交易必須是這張訂單的：商家訂單編號與金額都要對得上。
		if ( null !== $record ) {
			$record_number = (string) ( $record['order_number'] ?? '' );
			$record_amount = isset( $record['amount'] ) ? (int) $record['amount'] : -1;
			if ( $record_number !== $expected_number || $record_amount !== (int) round( (float) $order->get_total() ) ) {
				Helper::log(
					'3DS result record does not belong to this order — ignored',
					[
						'order_id'      => $order->get_id(),
						'record_number' => $record_number,
						'record_amount' => $record_amount,
					]
				);
				$order->add_order_note( __( 'The TapPay transaction returned for this order does not match its order number or amount, so it was not used to mark the order as paid.', 'moksa-for-woocommerce' ) );
				$record = null;
			}
		}
		Helper::log(
			'trade record query',
			[
				'order_id'     => $order->get_id(),
				'rec_trade_id' => $rec_trade_id,
				'record'       => $record,
			]
		);

		return $record;
	}

	/** 3D 驗證與電子錢包付款時，TapPay 不會送付款通知給待付款的交易，要自己補查。 */
	public static function schedule_pending_check( \WC_Order $order, int $attempt = 1 ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$args = [ $order->get_id(), $attempt ];
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::PENDING_CHECK_HOOK, $args, 'moksa-for-woocommerce' ) ) {
			return;
		}
		// TapPay 自己會在 10～20 分鐘內反查銀行，排在那之後。
		as_schedule_single_action( time() + ( 1 === $attempt ? 25 : 60 ) * MINUTE_IN_SECONDS, self::PENDING_CHECK_HOOK, $args, 'moksa-for-woocommerce' );
	}

	public static function check_pending( $order_id, $attempt = 1 ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || $order->is_paid() || ! str_starts_with( (string) $order->get_payment_method(), 'moksafowo_tappay' ) ) {
			return;
		}
		// 含已取消：待付款太久會被 WC 保留庫存逾時自動取消，但顧客其實可能已經付款。
		if ( ! $order->has_status( [ 'pending', 'on-hold', 'failed', 'cancelled' ] ) ) {
			return;
		}
		$record = self::query_verified_record( $order );
		if ( null === $record ) {
			// 查不到交易或查詢失敗：再查幾次，最後留備註，不讓訂單無聲無息地停在保留。
			if ( (int) $attempt < 3 ) {
				self::schedule_pending_check( $order, (int) $attempt + 1 );
			} elseif ( $order->has_status( 'on-hold' ) ) {
				$order->add_order_note( __( 'TapPay still has no confirmed transaction for this order. Check it in the TapPay portal; if the customer was not charged, change this order to Failed so it can be paid again.', 'moksa-for-woocommerce' ) );
			}
			return;
		}
		self::write_transaction_meta( $order, $record );
		self::apply_status( $order, $record );
		$order->save();
		if ( 4 === (int) ( $record['record_status'] ?? -1 ) && (int) $attempt < 3 ) {
			self::schedule_pending_check( $order, (int) $attempt + 1 );
		}
	}

	private static function resolve_order( string $order_number ): ?\WC_Order {
		if ( '' === $order_number ) {
			return null;
		}
		// 先用訂單上記下的 order_number 精確比對；解析出來的數字只在那張訂單送出的就是這個編號時才採用。
		// 用了自訂訂單編號外掛時，編號的數字會對到另一張訂單的 ID。
		$id = Helper::parse_order_id( $order_number );
		if ( $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				$sent = (string) $order->get_meta( Keys::TAPPAY_ORDER_NUMBER );
				if ( '' !== $sent ? hash_equals( $sent, $order_number ) : Helper::build_order_number( $order ) === $order_number ) {
					return $order;
				}
			}
		}
		$found = wc_get_orders(
			[
				'limit'      => 1,
				'return'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
				'meta_key'   => Keys::TAPPAY_ORDER_NUMBER,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
				'meta_value' => $order_number,
			]
		);
		if ( empty( $found ) ) {
			return null;
		}
		$order = wc_get_order( (int) $found[0] );
		return $order instanceof \WC_Order ? $order : null;
	}

	private static function write_transaction_meta( \WC_Order $order, array $payload ): void {
		$map = [
			'rec_trade_id'        => Keys::TAPPAY_REC_TRADE_ID,
			'bank_transaction_id' => Keys::TAPPAY_BANK_TRANSACTION_ID,
			'auth_code'           => Keys::TAPPAY_AUTH_CODE,
		];
		foreach ( $map as $field => $key ) {
			if ( isset( $payload[ $field ] ) && '' !== (string) $payload[ $field ] ) {
				$order->update_meta_data( $key, (string) $payload[ $field ] );
			}
		}

		if ( isset( $payload['status'] ) ) {
			$order->update_meta_data( Keys::TAPPAY_TRANSACTION_STATUS, (string) $payload['status'] );
		}

		$card = isset( $payload['card_info'] ) && is_array( $payload['card_info'] ) ? $payload['card_info'] : $payload;
		if ( isset( $card['last_four'] ) && '' !== (string) $card['last_four'] ) {
			$order->update_meta_data( Keys::TAPPAY_CARD_LAST4, (string) $card['last_four'] );
		}
		if ( isset( $card['bin_code'] ) && '' !== (string) $card['bin_code'] ) {
			$order->update_meta_data( Keys::TAPPAY_CARD_BIN, (string) $card['bin_code'] );
		}
		if ( isset( $card['issuer'] ) && '' !== (string) $card['issuer'] ) {
			$order->update_meta_data( Keys::TAPPAY_CARD_ISSUER, (string) $card['issuer'] );
		}

		if ( isset( $payload['transaction_time_millis'] ) ) {
			$order->update_meta_data( Keys::TAPPAY_PAID_AT, (string) $payload['transaction_time_millis'] );
		}
	}

	private static function apply_status( \WC_Order $order, array $payload ): void {
		// 付款通知帶 `status`（0=成功）；從付款頁返回時查交易紀錄，只有 `record_status`。
		// 付款通知與付款回應只看 status（官方欄位表沒有 record_status）；交易紀錄才用 record_status。
		// record_status 依 TapPay 官方定義：-1 錯誤、0 已授權未請款、1 交易完成（已請款）、
		// 2 部分退款、3 完全退款、4 待付款、5 取消交易。0 和 1 都算已付款。
		$has_status    = array_key_exists( 'status', $payload );
		$status        = $has_status ? (int) $payload['status'] : -1;
		$record_status = isset( $payload['record_status'] ) ? (int) $payload['record_status'] : null;
		$rec_trade_id  = (string) ( $payload['rec_trade_id'] ?? $order->get_meta( Keys::TAPPAY_REC_TRADE_ID ) );
		if ( $has_status ) {
			$record_status = null;
		}

		if ( 2 === $record_status || 3 === $record_status ) {
			$order->add_order_note(
				2 === $record_status
					? __( 'This transaction was partially refunded in the TapPay dashboard.', 'moksa-for-woocommerce' )
					: __( 'This transaction was refunded in the TapPay dashboard.', 'moksa-for-woocommerce' )
			);
			return;
		}

		$paid = $has_status ? 0 === $status : in_array( $record_status, [ 0, 1 ], true );
		// 已付款的訂單不會被晚到的等待驗證／失敗結果打回。
		if ( ! $paid && $order->is_paid() ) {
			return;
		}
		if ( $paid ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $rec_trade_id );
				\Moksafowo\Modules\Tappay\SubscriptionRenewal::promote_pending_card( $order );
				// 同一個通知網址也收 LINE Pay、街口等錢包付款，不能寫死「信用卡」；錢包沒有卡號末四碼，空值不印。
				$order->add_order_note(
					\Moksafowo\Order\Note::build(
						sprintf(
							/* translators: %s: payment method title */
							__( '%s payment completed', 'moksa-for-woocommerce' ),
							$order->get_payment_method_title() ?: 'TapPay'
						),
						[
							__( 'Transaction ID', 'moksa-for-woocommerce' ) => $rec_trade_id,
							__( 'Last four card digits', 'moksa-for-woocommerce' ) => (string) $order->get_meta( Keys::TAPPAY_CARD_LAST4 ),
						]
					)
				);
			}
			return;
		}

		// 待付款（例如 3D 驗證或銀行尚未確認）：維持等待付款，結果由之後的付款通知決定。
		if ( 4 === $record_status ) {
			if ( ! $order->has_status( 'pending' ) ) {
				$order->update_status( 'pending', __( 'TapPay is waiting for the payment to be confirmed.', 'moksa-for-woocommerce' ) );
			}
			return;
		}
		if ( 5 === $record_status ) {
			$order->update_status( 'cancelled', __( 'The TapPay transaction was cancelled.', 'moksa-for-woocommerce' ) );
			return;
		}

		$msg  = (string) ( $payload['msg'] ?? ( $payload['bank_result_msg'] ?? '' ) );
		$code = $has_status ? $status : (int) ( $record_status ?? -1 );
		$order->update_status(
			'failed',
			sprintf(
				/* translators: 1: status code, 2: message */
				__( 'The TapPay payment failed (status code %1$d): %2$s', 'moksa-for-woocommerce' ),
				$code,
				$msg
			)
		);
	}

	private static function first_trade_record( array $data ): ?array {
		if ( isset( $data['trade_records'][0] ) && is_array( $data['trade_records'][0] ) ) {
			return $data['trade_records'][0];
		}
		if ( isset( $data['rec_trade_id'] ) ) {
			return $data;
		}
		return null;
	}

	private static function header( string $name ): string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}
}
