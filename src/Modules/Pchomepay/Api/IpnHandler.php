<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Pchomepay\Api;

use MoksaWeb\Mowc\Order\Lookup;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- IPN webhook; CheckMacValue / HMAC / RSA signature verified inside this method.
		// 1. IP 白名單 — 第一道防線。
		$ip = self::client_ip();
		if ( Helper::NOTIFY_IP !== $ip ) {
			Helper::log( 'webhook rejected — IP not allowed', [ 'ip' => $ip ] );
			status_header( 403 );
			echo 'forbidden';
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing — 匿名 webhook，走 IP 白名單 + order_id 對應
		$posted = wp_unslash( $_POST );
		// phpcs:enable

		$notify_type = isset( $posted['notify_type'] ) ? (string) $posted['notify_type'] : '';
		$raw_message = isset( $posted['notify_message'] ) ? (string) $posted['notify_message'] : '';

		if ( '' === $notify_type || '' === $raw_message ) {
			Helper::log( 'webhook missing notify_type / notify_message' );
			status_header( 400 );
			echo 'bad request';
			exit;
		}

		$message = json_decode( $raw_message, true );
		if ( ! is_array( $message ) ) {
			Helper::log( 'webhook notify_message not JSON', [ 'notify_type' => $notify_type ] );
			status_header( 400 );
			echo 'bad request';
			exit;
		}

		Helper::log( 'webhook received', [
			'notify_type' => $notify_type,
			'message'     => $message,
		] );

		$pchome_order_id = (string) ( $message['order_id'] ?? '' );
		$order_id        = Helper::parse_order_id( $pchome_order_id );
		if ( ! $order_id ) {
			$order_id = Lookup::by_meta( Keys::PCHOMEPAY_ORDER_ID, $pchome_order_id );
		}
		if ( ! $order_id ) {
			Helper::log( 'webhook order not found', [ 'order_id' => $pchome_order_id ] );
			status_header( 404 );
			echo 'order not found';
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			status_header( 404 );
			echo 'order not loadable';
			exit;
		}

		self::write_common_meta( $order, $message );
		self::apply_notify( $order, $notify_type, $message );
		$order->save();

		\MoksaWeb\Mowc\Modules\Shared\Email\PaymentInfoEmailDispatcher::maybe_dispatch( $order );

		// 3 秒內回純文字 success（不是 JSON）。
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'success';
		exit;
	}

	private static function apply_notify( \WC_Order $order, string $notify_type, array $message ): void {
		switch ( $notify_type ) {
			case 'order_confirm':
				if ( ! $order->is_paid() ) {
					$order->payment_complete( (string) ( $message['order_id'] ?? '' ) );
				}
				$order->add_order_note( sprintf(
					/* translators: 1: pay type, 2: amount */
					__( '支付連付款完成 — %1$s（金額 NT$%2$s）', 'mo-ectools' ),
					self::pay_type_label( (string) ( $message['pay_type'] ?? '' ) ),
					(string) ( $message['trade_amount'] ?? $message['amount'] ?? '' )
				) );
				break;

			case 'order_audit':
				// ATM 取號 / 超商代碼取條碼 / 超商取貨選好門市 — 尚未付款，留 on-hold。
				$order->update_status( 'on-hold', sprintf(
					/* translators: %s: pay type */
					__( '支付連已產生 %s 付款資訊，等待顧客付款。', 'mo-ectools' ),
					self::pay_type_label( (string) ( $message['pay_type'] ?? '' ) )
				) );
				break;

			case 'order_expired':
				$order->update_status( 'cancelled', __( '支付連訂單逾時未付款。', 'mo-ectools' ) );
				break;

			case 'order_failed':
				$order->update_status( 'failed', sprintf(
					/* translators: %s: status code */
					__( '支付連付款失敗（狀態碼 %s）。', 'mo-ectools' ),
					(string) ( $message['status_code'] ?? '' )
				) );
				break;

			case 'refund_success':
				$order->add_order_note( sprintf(
					/* translators: 1: refund id, 2: amount */
					__( '支付連退款成功 — 退款編號 %1$s（NT$%2$s）', 'mo-ectools' ),
					(string) ( $message['refund_id'] ?? '' ),
					(string) ( $message['trade_amount'] ?? $message['refund_amount'] ?? '' )
				) );
				break;

			case 'seller_dispatched':
			case 'pickup_shipped':
			case 'return_shipped':
				$order->update_meta_data( Keys::PCHOMEPAY_LOGISTIC_STATUS, $notify_type );
				$order->add_order_note( sprintf(
					/* translators: %s: logistic stage */
					__( '支付連物流狀態更新：%s', 'mo-ectools' ),
					self::logistic_label( $notify_type )
				) );
				break;

			default:
				$order->add_order_note( sprintf(
					/* translators: %s: notify type */
					__( '支付連通知：%s', 'mo-ectools' ),
					$notify_type
				) );
		}
	}

	private static function write_common_meta( \WC_Order $order, array $message ): void {
		$map = [
			'pay_type'        => Keys::PCHOMEPAY_PAY_TYPE,
			'status'          => Keys::PCHOMEPAY_STATUS,
			'status_code'     => Keys::PCHOMEPAY_STATUS_CODE,
			'trade_amount'    => Keys::PCHOMEPAY_TRADE_AMOUNT,
			'platform_amount' => Keys::PCHOMEPAY_PLATFORM_AMOUNT,
			'pp_fee'          => Keys::PCHOMEPAY_PP_FEE,
			'pay_date'        => Keys::PCHOMEPAY_PAY_DATE,
		];
		foreach ( $map as $field => $key ) {
			if ( isset( $message[ $field ] ) && '' !== (string) $message[ $field ] ) {
				$order->update_meta_data( $key, (string) $message[ $field ] );
			}
		}

		$info = isset( $message['payment_info'] ) && is_array( $message['payment_info'] )
			? $message['payment_info']
			: $message;

		if ( isset( $info['card_last_no'] ) || isset( $info['card_last4'] ) ) {
			$order->update_meta_data( Keys::PCHOMEPAY_CARD_LAST4, (string) ( $info['card_last_no'] ?? $info['card_last4'] ) );
		}
		if ( isset( $info['virtual_account'] ) ) {
			$order->update_meta_data( Keys::PCHOMEPAY_VIRTUAL_ACCOUNT, (string) $info['virtual_account'] );
		}
		// notify 的 payment_info 用 bank_code；同步 /atmva API 回應才用 bank_id。兩者都讀。
		if ( isset( $info['bank_id'] ) || isset( $info['atm_bank'] ) || isset( $info['bank_code'] ) ) {
			$order->update_meta_data( Keys::PCHOMEPAY_BANK_CODE, (string) ( $info['bank_id'] ?? $info['atm_bank'] ?? $info['bank_code'] ) );
		}
		if ( isset( $info['expire_date'] ) && '' !== (string) $info['expire_date'] ) {
			$order->update_meta_data( Keys::PCHOMEPAY_EXPIRE_DATE, (string) $info['expire_date'] );
		}
		if ( isset( $info['pincode'] ) ) {
			$order->update_meta_data( Keys::PCHOMEPAY_PINCODE, (string) $info['pincode'] );
		}
		foreach ( [ 'barcode1' => Keys::PCHOMEPAY_BARCODE_1, 'barcode2' => Keys::PCHOMEPAY_BARCODE_2, 'barcode3' => Keys::PCHOMEPAY_BARCODE_3 ] as $f => $k ) {
			if ( isset( $info[ $f ] ) ) {
				$order->update_meta_data( $k, (string) $info[ $f ] );
			}
		}
		if ( isset( $info['store_id'] ) ) {
			$order->update_meta_data( Keys::PCHOMEPAY_LOGISTIC_ID, (string) $info['store_id'] );
		}
	}

	private static function client_ip(): string {
		// PChomePay 直連，不經 proxy — 用 REMOTE_ADDR（不信任 X-Forwarded-For）。
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	private static function pay_type_label( string $type ): string {
		return \MoksaWeb\Mowc\Modules\Pchomepay\PaymentTypeCatalog::label( $type, $type );
	}

	private static function logistic_label( string $notify_type ): string {
		return \MoksaWeb\Mowc\Modules\Pchomepay\PaymentTypeCatalog::logistic_label( $notify_type, $notify_type );
	}

}
