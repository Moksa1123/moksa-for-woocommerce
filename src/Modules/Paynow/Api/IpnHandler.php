<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Paynow\Api;

use MoksaWeb\Mowc\Http\Response;
use MoksaWeb\Mowc\Order\Lookup;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class IpnHandler {

	private const IMMEDIATE_TYPES = [ '01', '02', '09', '11' ];

	public static function handle(): void {
		// PayNow gateway IPN: no WP nonce possible (external server cannot send one).
		// Source authenticity verified via PassCode (SHA1 + hash_equals, self::verify_pass_code) on line ~62
		// before any order state change. All fields sanitized at capture via map_deep + sanitize_text_field.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- PayNow gateway IPN; no WP nonce possible; source verified via PassCode hash_equals before any state change; all fields sanitized at capture via map_deep.
		$source = ! empty( $_POST ) ? wp_unslash( $_POST ) : wp_unslash( $_GET );

		if ( empty( $source ) || ! is_array( $source ) ) {
			Helper::log( 'callback empty — rejected' );
			self::reply( 400, 'EMPTY' );
		}

		$posted = array_map(
			static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v,
			$source
		);

		Helper::log( 'callback received', [ 'data' => $posted ] );

		$order_no   = self::pick( $posted, [ 'OrderNo' ] );
		$total      = self::pick( $posted, [ 'TotalPrice' ] );
		$pay_type   = self::pick( $posted, [ 'PayType' ] );
		$tran       = self::pick( $posted, [ 'TranStatus' ] );
		$pass_code  = self::pick( $posted, [ 'PassCode' ] );
		$buysafe_no = self::pick( $posted, [ 'BuysafeNo', 'BuySafeNo' ] );

		if ( '' === $order_no || '' === $pass_code || '' === $pay_type ) {
			Helper::log( 'callback missing OrderNo / PassCode / PayType — rejected' );
			self::reply( 400, 'MISSING' );
		}

		$order_id = Helper::parse_order_id( $order_no );
		if ( ! $order_id ) {
			$order_id = Lookup::by_meta( Keys::PAYNOW_ORDER_NO, $order_no );
		}
		if ( ! $order_id ) {
			Helper::log( 'callback order not found', [ 'order_no' => $order_no ] );
			self::reply( 404, 'ORDER_NOT_FOUND' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			self::reply( 404, 'ORDER_NOT_LOADABLE' );
		}

		$is_success = 'S' === strtoupper( $tran );

		if ( ! self::verify_pass_code( $pay_type, $order_no, $total, $tran, $is_success, $pass_code ) ) {
			Helper::log(
				'callback PassCode mismatch — possible forgery',
				[
					'order_no' => $order_no,
					'pay_type' => $pay_type,
				]
			);
			self::reply( 400, 'PASSCODE_MISMATCH' );
		}

		if ( '05' === $pay_type && $is_success ) {
			$pass_code2 = self::pick( $posted, [ 'PassCode2' ] );
			$email      = (string) $order->get_billing_email();
			// PassCode2 為此付款方式的必要驗證,未帶或不符一律拒絕(fail-closed)。
			if ( '' === $pass_code2 || '' === $email
				|| ! Signature::verify( Signature::make_pass_code2( $pass_code, $email ), $pass_code2 ) ) {
				Helper::log( 'callback PassCode2 mismatch — rejected', [ 'order_no' => $order_no ] );
				self::reply( 400, 'PASSCODE2_MISMATCH' );
			}
		}

		self::write_common_meta( $order, $order_no, $pay_type, $tran, $buysafe_no, $posted );
		self::write_paytype_meta( $order, $pay_type, $posted );
		self::apply_status( $order, $pay_type, $is_success, $posted );
		$order->save();

		\MoksaWeb\Mowc\Modules\Shared\Email\PaymentInfoEmailDispatcher::maybe_dispatch( $order );

		self::reply( 200, 'OK' );
	}

	private static function verify_pass_code(
		string $pay_type,
		string $order_no,
		string $total,
		string $tran,
		bool $is_success,
		string $actual
	): bool {
		$web_no = Helper::web_no();
		$pass   = Helper::trade_password();
		if ( '' === $web_no || '' === $pass ) {
			return false;
		}

		$total = (string) (int) $total;

		$base = [ $web_no, $order_no, $total, $pass ];

		if ( in_array( $pay_type, self::IMMEDIATE_TYPES, true ) ) {
			$expected = Signature::make( ...array_merge( $base, [ strtoupper( $tran ) ] ) );
			return Signature::verify( $expected, $actual );
		}

		if ( $is_success ) {
			$with_status = Signature::make( ...array_merge( $base, [ strtoupper( $tran ) ] ) );
			if ( Signature::verify( $with_status, $actual ) ) {
				return true;
			}
		}
		$four = Signature::make( ...$base );
		return Signature::verify( $four, $actual );
	}

	private static function write_common_meta(
		\WC_Order $order,
		string $order_no,
		string $pay_type,
		string $tran,
		string $buysafe_no,
		array $posted
	): void {
		$order->update_meta_data( Keys::PAYNOW_ORDER_NO, $order_no );
		$order->update_meta_data( Keys::PAYNOW_PAY_TYPE, $pay_type );
		if ( '' !== $tran ) {
			$order->update_meta_data( Keys::PAYNOW_TRAN_STATUS, strtoupper( $tran ) );
		}
		if ( '' !== $buysafe_no ) {
			$order->update_meta_data( Keys::PAYNOW_BUYSAFE_NO, $buysafe_no );
			$order->set_transaction_id( $buysafe_no );
		}
		$new_date = self::pick( $posted, [ 'NewDate' ] );
		if ( '' !== $new_date ) {
			$order->update_meta_data( Keys::PAYNOW_NEW_DATE, $new_date );
		}
		$err = self::pick( $posted, [ 'ErrDesc' ] );
		if ( '' !== $err ) {
			$order->update_meta_data( Keys::PAYNOW_ERR_DESC, $err );
		}
		$note1 = self::pick( $posted, [ 'Note1' ] );
		$note2 = self::pick( $posted, [ 'Note2' ] );
		if ( '' !== $note1 || '' !== $note2 ) {
			$order->update_meta_data( Keys::PAYNOW_NOTE, trim( $note1 . ' ' . $note2 ) );
		}
	}

	private static function write_paytype_meta( \WC_Order $order, string $pay_type, array $posted ): void {
		switch ( $pay_type ) {
			case '01':
			case '11':
				$last4 = self::pick( $posted, [ 'pan_no4' ] );
				if ( '' !== $last4 ) {
					$order->update_meta_data( Keys::PAYNOW_CARD_LAST4, $last4 );
				}
				$foreign = self::pick( $posted, [ 'Card_Foreign' ] );
				if ( '' !== $foreign ) {
					$order->update_meta_data( Keys::PAYNOW_CARD_FOREIGN, $foreign );
				}
				$inst = self::pick( $posted, [ 'installment' ] );
				if ( '' !== $inst ) {
					$order->update_meta_data( Keys::PAYNOW_INSTALLMENT, $inst );
				}
				break;

			case '03':
				$atm = self::pick( $posted, [ 'ATMNo' ] );
				if ( '' !== $atm ) {
					$order->update_meta_data( Keys::PAYNOW_ATM_NO, $atm );
				}
				$bank = self::pick( $posted, [ 'BankCode' ] );
				if ( '' !== $bank ) {
					$order->update_meta_data( Keys::PAYNOW_ATM_BANK_CODE, $bank );
				}
				$branch = self::pick( $posted, [ 'BranchCode' ] );
				if ( '' !== $branch ) {
					$order->update_meta_data( Keys::PAYNOW_ATM_BRANCH_CODE, $branch );
				}
				$due = self::pick( $posted, [ 'DueDate' ] );
				if ( '' !== $due ) {
					$order->update_meta_data( Keys::PAYNOW_ATM_DUE_DATE, $due );
				}
				break;

			case '10':
				foreach ( [
					'BarCode1' => Keys::PAYNOW_BARCODE_1,
					'BarCode2' => Keys::PAYNOW_BARCODE_2,
					'BarCode3' => Keys::PAYNOW_BARCODE_3,
				] as $f => $k ) {
					$v = self::pick( $posted, [ $f ] );
					if ( '' !== $v ) {
						$order->update_meta_data( $k, $v );
					}
				}
				$due = self::pick( $posted, [ 'DueDate' ] );
				if ( '' !== $due ) {
					$order->update_meta_data( Keys::PAYNOW_BARCODE_DUE_DATE, $due );
				}
				break;

			case '05':
				$ibon = self::pick( $posted, [ 'IBONNO' ] );
				if ( '' !== $ibon ) {
					$order->update_meta_data( Keys::PAYNOW_IBON_NO, $ibon );
				}
				$fami = self::pick( $posted, [ 'FamiPortNo' ] );
				if ( '' !== $fami ) {
					$order->update_meta_data( Keys::PAYNOW_FAMIPORT_NO, $fami );
				}
				$icash = self::pick( $posted, [ 'icashpayno' ] );
				if ( '' !== $icash ) {
					$order->update_meta_data( Keys::PAYNOW_ICASH_NO, $icash );
				}
				$icash_url = esc_url_raw( self::pick( $posted, [ 'icashpayurl' ] ) );
				if ( '' !== $icash_url ) {
					$order->update_meta_data( Keys::PAYNOW_ICASH_PAY_URL, $icash_url );
				}
				$code_type = self::pick( $posted, [ 'CodeType' ] );
				if ( '' !== $code_type ) {
					$order->update_meta_data( Keys::PAYNOW_CODE_TYPE, $code_type );
				}
				$due = self::pick( $posted, [ 'DueDate' ] );
				if ( '' !== $due ) {
					$order->update_meta_data( Keys::PAYNOW_CODE_DUE_DATE, $due );
				}
				break;
		}
	}

	private static function apply_status( \WC_Order $order, string $pay_type, bool $is_success, array $posted ): void {
		$label = self::pay_type_label( $pay_type );

		if ( $is_success ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( (string) $order->get_meta( Keys::PAYNOW_BUYSAFE_NO ) );
			}
			$order->add_order_note(
				sprintf(
				/* translators: %s: payment type */
					__( 'PayNow 付款完成 — %s', 'mo-ectools' ),
					$label
				)
			);
			return;
		}

		$err = self::pick( $posted, [ 'ErrDesc' ] );

		if ( ! in_array( $pay_type, self::IMMEDIATE_TYPES, true ) && '' === $err ) {
			$order->update_status(
				'on-hold',
				sprintf(
				/* translators: %s: payment type */
					__( 'PayNow 已產生 %s 付款資訊，等待顧客付款。', 'mo-ectools' ),
					$label
				)
			);
			return;
		}

		$order->update_status(
			'failed',
			sprintf(
			/* translators: 1: payment type, 2: error */
				__( 'PayNow %1$s 付款失敗：%2$s', 'mo-ectools' ),
				$label,
				'' !== $err ? $err : __( '未提供原因', 'mo-ectools' )
			)
		);
	}

	private static function pick( array $src, array $keys ): string {
		foreach ( $keys as $k ) {
			if ( isset( $src[ $k ] ) && '' !== (string) $src[ $k ] ) {
				return sanitize_text_field( (string) $src[ $k ] );
			}
		}
		return '';
	}

	private static function pay_type_label( string $type ): string {
		return \MoksaWeb\Mowc\Modules\Paynow\PaymentTypeCatalog::label( $type, $type );
	}

	private static function reply( int $status, string $body ): void {
		Response::send_plain( $status, $body );
	}
}
