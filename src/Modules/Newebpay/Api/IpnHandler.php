<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Api;

use Moksafowo\Order\Lookup;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public static function handle(): void {
		// Gateway IPN: no WP nonce possible (external server cannot send one).
		// Source authenticity verified via TradeSha (SHA256 + hash_equals, Helper::verify_trade_sha) on line ~28
		// before any decryption or state change. TradeInfo is AES-encrypted hex; sanitize_text_field is idempotent on hex.
		// Decoded payload is deep-sanitized via map_deep on line ~43 after decryption succeeds.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- gateway IPN; no WP nonce possible; source verified via TradeSha hash_equals before any use; decoded payload sanitized via map_deep after decryption.
		$trade_info = isset( $_POST['TradeInfo'] ) ? sanitize_text_field( wp_unslash( $_POST['TradeInfo'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same as above.
		$trade_sha = isset( $_POST['TradeSha'] ) ? sanitize_text_field( wp_unslash( $_POST['TradeSha'] ) ) : '';

		if ( '' === $trade_info || '' === $trade_sha ) {
			Helper::log( 'IPN missing TradeInfo / TradeSha — rejected' );
			status_header( 400 );
			echo 'Missing';
			exit;
		}

		if ( ! Helper::verify_trade_sha( $trade_info, $trade_sha ) ) {
			Helper::log( 'IPN TradeSha mismatch — rejected' );
			status_header( 400 );
			echo 'Sha mismatch';
			exit;
		}

		$decoded = Helper::decrypt_trade_info( $trade_info );
		if ( null === $decoded ) {
			Helper::log( 'IPN decrypt failed' );
			status_header( 400 );
			echo 'Decrypt fail';
			exit;
		}

		$decoded = map_deep( $decoded, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );

		Helper::log( 'IPN decoded', [ 'data' => $decoded ] );

		$status  = (string) ( $decoded['Status'] ?? '' );
		$message = (string) ( $decoded['Message'] ?? '' );
		$result  = isset( $decoded['Result'] ) ? (array) $decoded['Result'] : [];
		$mtn     = (string) ( $result['MerchantOrderNo'] ?? '' );

		$order_id = Helper::parse_order_id( $mtn );
		if ( ! $order_id ) {
			$order_id = Lookup::by_meta( Keys::NEWEBPAY_MERCHANT_ORDER_NO, $mtn );
		}
		if ( ! $order_id ) {
			Helper::log( 'IPN order not found', [ 'mtn' => $mtn ] );
			status_header( 404 );
			echo 'Order not found';
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			status_header( 404 );
			echo 'Order not loadable';
			exit;
		}

		$trade_no     = (string) ( $result['TradeNo'] ?? '' );
		$payment_type = (string) ( $result['PaymentType'] ?? '' );
		$pay_time     = (string) ( $result['PayTime'] ?? '' );
		$card4no      = (string) ( $result['Card4No'] ?? '' );
		$amt          = (int) ( $result['Amt'] ?? 0 );
		$check_code   = (string) ( $result['CheckCode'] ?? '' );

		// CheckCode 雙重驗證 (per NDNF 1.2.2 4.1.5) — 只有 SUCCESS + Result 帶 CheckCode 才驗
		if ( 'SUCCESS' === $status && '' !== $check_code && '' !== $trade_no ) {
			$expected_cc = Helper::generate_notify_check_code(
				[
					'Amt'             => $amt,
					'MerchantID'      => Helper::merchant_id(),
					'MerchantOrderNo' => $mtn,
					'TradeNo'         => $trade_no,
				]
			);
			if ( ! hash_equals( $expected_cc, strtoupper( $check_code ) ) ) {
				Helper::log(
					'IPN CheckCode mismatch — possible forgery',
					[
						'mtn'      => $mtn,
						'trade_no' => $trade_no,
					]
				);
				status_header( 400 );
				echo 'CheckCode mismatch';
				exit;
			}
		}

		if ( '' !== $trade_no ) {
			$order->update_meta_data( Keys::NEWEBPAY_TRADE_NO, $trade_no );
			$order->set_transaction_id( $trade_no );
		}
		if ( '' !== $payment_type ) {
			$order->update_meta_data( Keys::NEWEBPAY_PAYMENT_TYPE, $payment_type );
		}
		if ( '' !== $pay_time ) {
			$order->update_meta_data( Keys::NEWEBPAY_PAY_TIME, $pay_time );
		}
		if ( '' !== $card4no ) {
			$order->update_meta_data( Keys::NEWEBPAY_CARD_LAST4, $card4no );
		}

		self::write_extra_meta( $order, $payment_type, $result );
		self::maybe_write_shipping_store_meta( $order, $result );

		if ( 'SUCCESS' === $status ) {
			$order->payment_complete( $trade_no );
			$order->add_order_note(
				sprintf(
				/* translators: 1: payment type, 2: trade no */
					__( '藍新付款完成 — %1$s（交易編號 %2$s）', 'mo-ectools' ),
					self::payment_type_label( $payment_type ),
					$trade_no
				)
			);
		} elseif ( in_array( $status, [ 'CUSTOM', 'GETTING' ], true ) ) {
			$order->update_status(
				'on-hold',
				sprintf(
				/* translators: 1: payment type, 2: status */
					__( '藍新已產生 %1$s 付款資訊，等待顧客付款（狀態 %2$s）', 'mo-ectools' ),
					self::payment_type_label( $payment_type ),
					$status
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
				/* translators: 1: status, 2: message */
					__( '藍新付款失敗：%1$s（%2$s）', 'mo-ectools' ),
					$status,
					$message
				)
			);
			$order->update_status( 'failed' );
		}

		$order->save();

		\Moksafowo\Modules\Shared\Email\PaymentInfoEmailDispatcher::maybe_dispatch( $order );

		echo '1|OK';
		exit;
	}

	private static function write_extra_meta( \WC_Order $order, string $payment_type, array $result ): void {
		if ( 'VACC' === $payment_type ) {
			if ( isset( $result['BankCode'] ) ) {
				$order->update_meta_data( Keys::NEWEBPAY_ATM_BANK_CODE, (string) $result['BankCode'] );
			}
			if ( isset( $result['CodeNo'] ) ) {
				$order->update_meta_data( Keys::NEWEBPAY_ATM_CODE_NO, (string) $result['CodeNo'] );
			}
			if ( isset( $result['ExpireDate'] ) ) {
				$order->update_meta_data( Keys::NEWEBPAY_ATM_EXPIRE_DATE, (string) $result['ExpireDate'] );
			}
		} elseif ( 'CVS' === $payment_type ) {
			if ( isset( $result['CodeNo'] ) ) {
				$order->update_meta_data( Keys::NEWEBPAY_CVS_CODE_NO, (string) $result['CodeNo'] );
			}
			if ( isset( $result['ExpireDate'] ) ) {
				$order->update_meta_data( Keys::NEWEBPAY_CVS_EXPIRE_DATE, (string) $result['ExpireDate'] );
			}
		} elseif ( 'BARCODE' === $payment_type ) {
			$barcode_keys = [ Keys::NEWEBPAY_BARCODE_1, Keys::NEWEBPAY_BARCODE_2, Keys::NEWEBPAY_BARCODE_3 ];
			foreach ( [ 'Barcode_1', 'Barcode_2', 'Barcode_3' ] as $i => $key ) {
				if ( isset( $result[ $key ] ) ) {
					$order->update_meta_data( $barcode_keys[ $i ], (string) $result[ $key ] );
				}
			}
			if ( isset( $result['ExpireDate'] ) ) {
				$order->update_meta_data( Keys::NEWEBPAY_BARCODE_EXPIRE_DATE, (string) $result['ExpireDate'] );
			}
		}
	}

	private static function maybe_write_shipping_store_meta( \WC_Order $order, array $result ): void {
		$has_newebpay_shipping = false;
		foreach ( $order->get_shipping_methods() as $m ) {
			if ( str_starts_with( (string) $m->get_method_id(), 'moksafowo_newebpay_shipping_' ) ) {
				$has_newebpay_shipping = true;
				break;
			}
		}
		if ( ! $has_newebpay_shipping ) {
			return;
		}

		$store_id   = (string) ( $result['StoreCode'] ?? $result['StoreID'] ?? '' );
		$store_name = (string) ( $result['StoreName'] ?? '' );
		$store_addr = (string) ( $result['StoreAddr'] ?? $result['StoreAddress'] ?? '' );
		$lgs_no     = (string) ( $result['LgsNo'] ?? '' );
		$lgs_type   = (string) ( $result['LgsType'] ?? '' );

		if ( '' !== $store_id ) {
			$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_STORE_ID, $store_id );
		}
		if ( '' !== $store_name ) {
			$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_STORE_NAME, $store_name );
		}
		if ( '' !== $store_addr ) {
			$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_STORE_ADDR, $store_addr );
		}
		if ( '' !== $lgs_no ) {
			$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_LGS_NO, $lgs_no );
		}
		if ( '' !== $lgs_type ) {
			$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_LGS_TYPE, $lgs_type );
		}
	}

	private static function payment_type_label( string $type ): string {
		return \Moksafowo\Modules\Newebpay\PaymentTypeCatalog::label( $type, $type );
	}
}
