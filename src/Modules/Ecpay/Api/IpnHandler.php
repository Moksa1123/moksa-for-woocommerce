<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Ecpay\Api;

use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- IPN 走 CheckMacValue 不用 WP nonce
		$posted = $_POST;
		// phpcs:enable

		if ( empty( $posted ) ) {
			status_header( 400 );
			echo 'Empty';
			exit;
		}

		Helper::log( 'IPN raw', [ 'data' => $posted ] );

		if ( ! Helper::verify_check_mac_value( $posted ) ) {
			Helper::log( 'IPN CheckMacValue mismatch — rejected' );
			status_header( 400 );
			echo 'CheckMacValue mismatch';
			exit;
		}

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

		// 寫入 meta（永遠寫，不論成功失敗，作為 audit）
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

		// 1 = 成功（信用卡 / WebATM 即時）
		// 2 = ATM 取號成功（等銀行通知 PaymentInfoURL 才算付款）
		// 10100073 = CVS / Barcode 取號成功
		// 其他正數 = 失敗代碼
		if ( in_array( $rtn_code, [ 1, 2, 10100073 ], true ) ) {
			if ( 1 === $rtn_code ) {
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $trade_no );
					// WC payment_complete() 會自動加「透過 [gateway title] 付款 (TradeNo)」note，
					// 所以這裡只補 ECPay 特定資訊（Payment type 翻譯）避免重複。
					$order->add_order_note( sprintf(
						/* translators: %s: 中文化的付款方式名稱 */
						__( '付款方式：%s', 'mo-ectools' ),
						self::payment_type_label( (string) $payment_type )
					) );
				}
			} else {
				// 取號類付款（ATM 虛擬帳號 / CVS 繳費代碼 / 條碼）— 存付款資訊供顧客付款。
				// ECPay 透過 PaymentInfoURL（= 同一 IPN endpoint）回傳這些欄位。
				self::store_payment_info( $order, $posted );
				$note = sprintf(
					/* translators: 1: 中文化的付款方式名稱, 2: rtn code */
					__( '綠界取號完成（%1$s，等待付款。狀態代碼 %2$s）', 'mo-ectools' ),
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
				__( '綠界付款失敗：%1$s（狀態代碼 %2$s）', 'mo-ectools' ),
				wc_clean( wp_unslash( (string) $msg ) ),
				(string) $rtn_code
			);
			// 付款失敗 → 標記 failed（與 Newebpay / SmilePay / PAYUNi 一致）。
			// 過去只加 note 不轉狀態 → 失敗單滯留 pending，恐被 WC 保留庫存逾時取消。
			// 已付款（IPN 重送 / 競態）則不降級，只記 note。
			if ( ! $order->is_paid() ) {
				$order->update_status( 'failed', $note );
			} else {
				$order->add_order_note( $note );
			}
		}

		$order->save();

		// 取號類（RtnCode 2 / 10100073）— 觸發獨立繳費通知 Email（一次性，避免重送）。
		if ( in_array( $rtn_code, [ 2, 10100073 ], true ) && ! $order->get_meta( Keys::PAYMENT_INFO_EMAIL_SENT ) ) {
			$order->update_meta_data( Keys::PAYMENT_INFO_EMAIL_SENT, '1' );
			$order->save();
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
			do_action( 'mo_payment_info_email', $order->get_id() );
		}

		// ECPay 看到 "1|OK" 就不重發
		echo '1|OK';
		exit;
	}

	
	private static function store_payment_info( \WC_Order $order, array $posted ): void {
		$get = static fn( string $k ): string => isset( $posted[ $k ] ) ? wc_clean( wp_unslash( (string) $posted[ $k ] ) ) : '';

		// ATM 虛擬帳號
		if ( '' !== $get( 'vAccount' ) ) {
			$order->update_meta_data( Keys::ECPAY_ATM_BANK_CODE, $get( 'BankCode' ) );
			$order->update_meta_data( Keys::ECPAY_ATM_V_ACCOUNT, $get( 'vAccount' ) );
			$order->update_meta_data( Keys::ECPAY_ATM_EXPIRE_DATE, $get( 'ExpireDate' ) );
		}
		// CVS 繳費代碼
		if ( '' !== $get( 'PaymentNo' ) ) {
			$order->update_meta_data( Keys::ECPAY_CVS_PAYMENT_NO, $get( 'PaymentNo' ) );
			$order->update_meta_data( Keys::ECPAY_CVS_EXPIRE_DATE, $get( 'ExpireDate' ) );
		}
		// 超商條碼（三段）
		if ( '' !== $get( 'Barcode1' ) ) {
			$order->update_meta_data( Keys::ECPAY_BARCODE_1, $get( 'Barcode1' ) );
			$order->update_meta_data( Keys::ECPAY_BARCODE_2, $get( 'Barcode2' ) );
			$order->update_meta_data( Keys::ECPAY_BARCODE_3, $get( 'Barcode3' ) );
			$order->update_meta_data( Keys::ECPAY_BARCODE_EXPIRE_DATE, $get( 'ExpireDate' ) );
		}
	}

	private static function payment_type_label( string $raw ): string {
		return \MoksaWeb\Mowc\Modules\Ecpay\PaymentTypeCatalog::label( $raw, $raw );
	}

}
