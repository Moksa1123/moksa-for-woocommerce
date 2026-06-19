<?php
namespace MoksaWeb\Mowc\Modules\Payuni\Api;

use MoksaWeb\Mowc\Modules\Payuni\Credentials;
use MoksaWeb\Mowc\Modules\Payuni\PayuniPayment;
use MoksaWeb\Mowc\Modules\Payuni\Utils\CloseStatus;
use MoksaWeb\Mowc\Modules\Payuni\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\Payuni\Utils\TradeStatus;

defined( 'ABSPATH' ) || exit;

class PaymentRequest {

	protected $gateway;

	public static function redact_for_log( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		static $sensitive = [
			'BuyerEmail',
			'BuyerName',
			'BuyerTel',
			'BuyerAddress',
			'CardNumber',
			'CardLast4',
			'AccountLast5',
			'Card4No',
			'UsrMail',
			'UsrEmail',
			'UsrPhone',
			'PayerEmail',
			'PayerName',
			'PayerTel',
		];
		$copy             = $data;
		foreach ( $sensitive as $k ) {
			if ( isset( $copy[ $k ] ) ) {
				$copy[ $k ] = '[REDACTED]';
			}
		}
		if ( isset( $copy['Result'] ) && is_array( $copy['Result'] ) ) {
			$copy['Result'] = array_map( [ self::class, 'redact_for_log' ], $copy['Result'] );
		}
		return $copy;
	}

	public function get_transaction_args( $order ) {

		$prod_desc = array();
		$items     = $order->get_items();
		foreach ( $items as $item ) {
			$prod_desc[] = $item->get_name() . ' * ' . $item->get_quantity();
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		$encrypt_info = apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
			'moksafowo_payuni_transaction_args_' . $this->gateway->id,
			array(
				'MerID'      => $this->gateway->get_merchant_id(),
				'MerTradeNo' => PayuniPayment::build_payuni_order_no( $order->get_id() ),
				'TradeAmt'   => (int) $order->get_total(),
				'ProdDesc'   => implode( ';', $prod_desc ),
				'ReturnURL'  => $this->gateway->return_url, // 前景通知網址付款完成返回指定網址 (感謝頁面).
				'NotifyURL'  => $this->gateway->notify_url, // 幕後.
				'UsrMail'    => $order->get_billing_email(), // 付款頁帶入 email.
				'UsrMailFix' => '1', // 不可修改 email.
				'Timestamp'  => time(),
				'Lang'       => get_option( 'moksafowo_payuni_payment_language', 'zh-tw' ),
			),
			$order
		);

		if ( PayuniPayment::$einvoice_enabled ) {
			$encrypt_info['TradeInvoice'] = 1;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		$encrypt_info = apply_filters( 'moksafowo_payuni_transaction_args_data', $encrypt_info, $order );

		PayuniPayment::log( 'request encrypt info:' . wc_print_r( self::redact_for_log( $encrypt_info ), true ) );

		$encrypted_info = PayuniPayment::encrypt( $encrypt_info );

		$args = array(
			'MerID'       => $this->gateway->get_merchant_id(),
			'Version'     => '1.0',
			'EncryptInfo' => $encrypted_info,
			'HashInfo'    => PayuniPayment::hash_info( $encrypted_info ),
		);

		return $args;
	}

	public function build_request_form( $order ) {

		$order = wc_get_order( $order );

		try {
			?>
			<div><?php esc_html_e( 'Redirecting...', 'mo-ectools' ); ?></div>
			<form method="post" id="moksafowo-payuni-form" action="<?php echo esc_url( $this->gateway->get_api_url() ); ?>" accept="UTF-8" accept-charset="UTF-8">
			<?php
			$fields = $this->get_transaction_args( $order );

			PayuniPayment::log( 'request transaction args:' . wc_print_r( $fields, true ) );

			foreach ( $fields as $key => $value ) {
				echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '">';
			}
			?>
			</form>
			<?php

		} catch ( \Exception $e ) {
			PayuniPayment::log( $e->getMessage() . ' ' . $e->getTraceAsString() );
		}
	}

	public function refund( $order_id, $amount, $reason ) {
		$order = wc_get_order( $order_id );

		if ( false === $order ) {
			return new \WP_Error(
				'process_refund_request',
				/* translators:  %s is the order id */
				sprintf( __( 'Unable to find order #%s', 'mo-ectools' ), $order_id ),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $amount,
				)
			);
		}

		$payment_method           = $order->get_payment_method();
		$allowed_install_payments = PayuniPayment::get_allowed_install_payments( $order );
		if ( array_key_exists( $payment_method, $allowed_install_payments ) ) {
			if ( $order->get_total() !== $amount ) {
				return new \WP_Error(
					'process_refund_request',
					/* translators:  %s is the order id */
					sprintf( __( 'The refund amount for order #%s should be the same as the order total for installment payment.', 'mo-ectools' ), $order_id ),
					array(
						'order_id' => $order_id,
					)
				);
			}
		}

		$mer_id         = Credentials::merchant_id();
		$transaction_id = $order->get_transaction_id();

		if ( empty( $transaction_id ) ) {
			return new \WP_Error(
				'process_refund_request',
				/* translators:  %s is the order id */
				sprintf( __( 'Unable to find transaction id for order #%s', 'mo-ectools' ), $order_id ),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $amount,
				)
			);
		}

		$query_result = self::query( $order_id, false );
		if ( false === $query_result ) {
			return new \WP_Error(
				'process_refund_request',
				/* translators: %s: order id */
				sprintf( __( '退款前查詢訂單狀態失敗(訂單 #%s)。', 'mo-ectools' ), $order_id ),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $amount,
				)
			);
		}

		$trade_status = $query_result['TradeStatus'];

		if ( TradeStatus::PAID === $trade_status ) {

			$close_status = $query_result['CloseStatus'];
			if ( self::is_refundable( $close_status ) ) {
				$encrypt_info = array(
					'MerID'     => $mer_id,
					'TradeNo'   => $transaction_id,
					'Timestamp' => time(),
					'CloseType' => 2,
					'TradeAmt'  => $amount,
				);
				$url          = Credentials::test_mode_enabled() ? 'https://sandbox-api.payuni.com.tw/api/trade/close' : 'https://api.payuni.com.tw/api/trade/close';
			} else {
				/* translators: %s: PAYUNi settlement status */
				$order->add_order_note( sprintf( __( '此訂單目前狀態無法退款（PAYUNi 結算狀態：%s）', 'mo-ectools' ), $close_status ) );
				return new \WP_Error(
					'process_refund_request',
					/* translators: 1: trade status, 2: close status */
					sprintf( __( '此訂單無法退款（交易狀態：%1$s／結算狀態：%2$s）', 'mo-ectools' ), $trade_status, $close_status ),
					array(
						'order_id'      => $order_id,
						'refund_amount' => $amount,
					)
				);
			}

			PayuniPayment::log( 'refund url:' . $url );
		} else {
			return new \WP_Error(
				'process_refund_request',
				/* translators:  %s is the TradeStatus of the order */
				sprintf( __( 'Unable to Refund this Order. TradeStatus:%s', 'mo-ectools' ), $trade_status ),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $amount,
				)
			);
		}

		PayuniPayment::log( 'encrypt_info:' . wc_print_r( self::redact_for_log( $encrypt_info ), true ) );

		$encrypted_info = PayuniPayment::encrypt( $encrypt_info );
		$form_data      = array(
			'MerID'       => $mer_id,
			'Version'     => '1.0',
			'EncryptInfo' => $encrypted_info,
			'HashInfo'    => PayuniPayment::hash_info( $encrypted_info ),
		);
		PayuniPayment::log( 'form data:' . wc_print_r( $form_data, true ) );

		$request_args = array(
			'httpversion' => '1.1',
			'timeout'     => '30',
			'body'        => $form_data,
		);

		$response = wp_remote_post( $url, $request_args );
		if ( is_wp_error( $response ) ) {
			PayuniPayment::log( 'refund error:' . $response->get_error_message() );
			return false;
		}

		$response_body = wp_remote_retrieve_body( $response );
		PayuniPayment::log( 'refund response body:' . wc_print_r( $response_body, true ) );

		$result    = json_decode( $response_body, true );
		$decrypted = PayuniPayment::decrypt( $result['EncryptInfo'] );
		if ( 'SUCCESS' === $result['Status'] ) {
			/* translators: 1: refund amount, 2: PAYUNi message */
			$order->add_order_note( sprintf( __( 'PAYUNi 退款成功（金額 %1$s）：%2$s', 'mo-ectools' ), $amount, $decrypted['Message'] ) );
			return true;
		} else {
			/* translators: 1: PAYUNi error message, 2: status code */
			$order->add_order_note( sprintf( __( 'PAYUNi 退款失敗：%1$s（狀態 %2$s）', 'mo-ectools' ), $decrypted['Message'], $result['Status'] ) );
			throw new \Exception( 'PAYUNi refund failed. Status:' . esc_html( $result['Status'] ) );
		}
	}

	public static function query( $order_id, $add_note = true ) {

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			PayuniPayment::log( 'PAYUNi query faied. No such order_id:' . $order_id );
			return false;
		}

		$mer_id = Credentials::merchant_id();

		$payuni_order_no_key = PayuniPayment::get_order_meta_key( $order, OrderMeta::PAYUNI_ORDER_NO );
		$payuni_order_no     = $order->get_meta( $payuni_order_no_key );

		$encrypt_info = array(
			'MerID'      => $mer_id,
			'MerTradeNo' => $payuni_order_no,
			'Timestamp'  => time(),
		);
		PayuniPayment::log( 'query encrypt info:' . wc_print_r( self::redact_for_log( $encrypt_info ), true ) );

		$encrypted_info = PayuniPayment::encrypt( $encrypt_info );

		$form_data = array(
			'MerID'       => $mer_id,
			'Version'     => '2.0',
			'EncryptInfo' => $encrypted_info,
			'HashInfo'    => PayuniPayment::hash_info( $encrypted_info ),
		);

		$request_args = array(
			'httpversion' => '1.1',
			'timeout'     => '30',
			'body'        => $form_data,
		);

		$url = Credentials::test_mode_enabled() ? 'https://sandbox-api.payuni.com.tw/api/trade/query' : 'https://api.payuni.com.tw/api/trade/query';
		PayuniPayment::log( 'query url:' . $url );
		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			PayuniPayment::log( 'query error:' . $response->get_error_message() );
			return false;
		}

		$response_body = wp_remote_retrieve_body( $response );
		PayuniPayment::log( 'query response body:' . wc_print_r( $response_body, true ) );

		$result    = json_decode( $response_body, true );
		$decrypted = PayuniPayment::decrypt( $result['EncryptInfo'] );
		PayuniPayment::log( 'query decrypted info:' . wc_print_r( self::redact_for_log( $decrypted ), true ) );

		if ( 'SUCCESS' === $result['Status'] ) {
			if ( ! isset( $decrypted['Result'] ) || ! is_array( $decrypted['Result'] ) || empty( $decrypted['Result'] ) ) {
				PayuniPayment::log( 'PAYUNi query returned SUCCESS but no Result data found.' );
				if ( $add_note ) {
					$order->add_order_note( __( 'PAYUNi 查詢回應成功，但查無訂單資料。', 'mo-ectools' ) );
				}
				return false;
			}

			$query_result                = array();
			$query_result['MerTradeNo']  = $decrypted['Result'][0]['MerTradeNo'];
			$query_result['TradeNo']     = $decrypted['Result'][0]['TradeNo'];
			$query_result['TradeStatus'] = $decrypted['Result'][0]['TradeStatus'];
			$query_result['PaymentDay']  = $decrypted['Result'][0]['PaymentDay'];
			$query_result['CreateDay']   = $decrypted['Result'][0]['CreateDay'];
			$query_result['PaymentType'] = $decrypted['Result'][0]['PaymentType'];

			if ( '1' === $query_result['PaymentType'] ) {
				$query_result['CloseStatus'] = $decrypted['Result'][0]['CloseStatus'];
			}

			$woo_order_id = PayuniPayment::parse_payuni_order_no_to_woo_order_id( $query_result['MerTradeNo'] );

			if ( $add_note ) {
				$order = wc_get_order( $woo_order_id );
				/* translators: %s: 查詢結果 */
				$order->add_order_note( sprintf( __( 'PAYUNi 訂單查詢成功，結果：%s', 'mo-ectools' ), wc_print_r( self::redact_for_log( $decrypted ), true ) ) );
			}
			PayuniPayment::log( 'PAYUNi query success. Status:' . $result['Status'] . ', Message:' . $decrypted['Message'] . ', Trade Status:' . $query_result['TradeStatus'] );
			return $query_result;
		} else {
			if ( $add_note ) {
				/* translators: %s: 查詢結果 */
				$order->add_order_note( sprintf( __( 'PAYUNi 訂單查詢失敗，結果：%s', 'mo-ectools' ), wc_print_r( self::redact_for_log( $decrypted ), true ) ) );
			}
			PayuniPayment::log( 'PAYUNi query failed. Status:' . $result['Status'] . ', Message:' . $decrypted['Message'] );
			return false;
		}
	}

	private static function is_refundable( $close_status ): bool {
		return CloseStatus::CAPTURE_OK === $close_status;
	}

	public function set_gateway( $gateway ) {
		$this->gateway = $gateway;
	}
}
