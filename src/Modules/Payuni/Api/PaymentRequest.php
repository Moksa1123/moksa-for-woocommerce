<?php
namespace Moksafowo\Modules\Payuni\Api;

use Moksafowo\Modules\Payuni\Credentials;
use Moksafowo\Modules\Payuni\PayuniPayment;
use Moksafowo\Modules\Payuni\Utils\CloseStatus;
use Moksafowo\Modules\Payuni\Utils\OrderMeta;
use Moksafowo\Modules\Payuni\Utils\PayType;
use Moksafowo\Modules\Payuni\Utils\TradeStatus;

defined( 'ABSPATH' ) || exit;

class PaymentRequest {

	/**
	 * 非信用卡的退款 API（trade/ 之後的路徑），依查單的 PaymentType。
	 * 官方 docs.payuni.com.tw/web/#/7/<頁>：愛金卡 page 72（只能全額）、AFTEE page 84、
	 * LINE Pay page 300、街口 page 377（全額或部分）；欄位都是 MerID、TradeNo、TradeAmt、Timestamp，Version 1.0。
	 */
	private const WALLET_REFUND_PATHS = [
		PayType::ICASH   => 'common/refund/icash',
		PayType::AFTEE   => 'common/refund/aftee',
		PayType::LINEPAY => 'common/refund/linepay',
		PayType::JKOPAY  => 'common/refund/jkopay',
	];

	/**
	 * 這次退款金額（結果未知時記進待處理清單用）。
	 *
	 * @var string
	 */
	private static string $refund_amount = '';

	/**
	 * 這次退款已經列進待處理（refund_failed 不再重複記）。
	 *
	 * @var bool
	 */
	private static bool $refund_flagged = false;

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
			'CreditHash',
			'CreditLife',
			'CreditToken',
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

		$encrypt_info = apply_filters(
			'moksafowo_payuni_transaction_args_' . $this->gateway->id,
			array(
				'MerID'      => $this->gateway->get_merchant_id(),
				'MerTradeNo' => PayuniPayment::build_payuni_order_no( $order->get_id() ),
				// 與其他金流一致無條件進位；回呼比對（PaymentResponse）接受 round 或 ceil。
				'TradeAmt'   => (int) ceil( (float) $order->get_total() ),
				// 官方 ProdDesc 上限 550 字，購物車品項多時會被整筆退回。
				'ProdDesc'   => mb_substr( implode( ';', $prod_desc ), 0, 500 ),
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

		$encrypt_info = apply_filters( 'moksafowo_payuni_transaction_args_data', $encrypt_info, $order );

		PayuniPayment::log( 'request encrypt info:' . wc_print_r( self::redact_for_log( $encrypt_info ), true ) );

		$encrypted_info = PayuniPayment::encrypt( $encrypt_info );

		$args = array(
			'MerID'       => $this->gateway->get_merchant_id(),
			// 信用卡 Token 參數是 UPP 2.0 的規格；其餘維持原本的 1.0。
			'Version'     => isset( $encrypt_info['UseTokenType'] ) ? '2.0' : '1.0',
			'EncryptInfo' => $encrypted_info,
			'HashInfo'    => PayuniPayment::hash_info( $encrypted_info ),
		);

		return $args;
	}

	public function build_request_form( $order ) {

		$order = wc_get_order( $order );

		try {
			?>
			<div><?php esc_html_e( 'Redirecting...', 'moksa-for-woocommerce' ); ?></div>
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
		$order                = wc_get_order( $order_id );
		self::$refund_amount  = (string) $amount;
		self::$refund_flagged = false;

		if ( false === $order ) {
			return new \WP_Error(
				'process_refund_request',
				/* translators:  %s is the order id */
				sprintf( __( 'Unable to find order #%s', 'moksa-for-woocommerce' ), $order_id ),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $amount,
				)
			);
		}

		$payment_method           = $order->get_payment_method();
		$allowed_install_payments = PayuniPayment::get_allowed_install_payments( $order );
		if ( array_key_exists( $payment_method, $allowed_install_payments ) ) {
			// get_total() 與退款金額可能是不同小數格式的字串（REST、其他外掛），以數值比較。
			if ( abs( (float) $order->get_total() - (float) $amount ) >= 0.01 ) {
				return new \WP_Error(
					'process_refund_request',
					/* translators:  %s is the order id */
					sprintf( __( 'The refund amount for order #%s should be the same as the order total for installment payment.', 'moksa-for-woocommerce' ), $order_id ),
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
				sprintf( __( 'Unable to find transaction id for order #%s', 'moksa-for-woocommerce' ), $order_id ),
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
				sprintf( __( 'The order status could not be checked before refunding (order #%s).', 'moksa-for-woocommerce' ), $order_id ),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $amount,
				)
			);
		}

		$trade_status = (string) $query_result['TradeStatus'];
		if ( TradeStatus::PAID !== $trade_status ) {
			return new \WP_Error(
				'process_refund_request',
				/* translators:  %s is the TradeStatus of the order */
				sprintf( __( 'Unable to Refund this Order. TradeStatus:%s', 'moksa-for-woocommerce' ), $trade_status ),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $amount,
				)
			);
		}

		$close_status = (string) ( $query_result['CloseStatus'] ?? '' );
		if ( '' === $close_status ) {
			// 只有信用卡類才有請款狀態。愛金卡、AFTEE、LINE Pay、街口走各自的退款 API；
			// 其他（ATM、超商代碼、取貨付款等）沒有退款 API，請店長到統一金流後台處理。
			$wallet = self::WALLET_REFUND_PATHS[ (int) ( $query_result['PaymentType'] ?? 0 ) ] ?? '';
			if ( '' !== $wallet ) {
				return self::refund_wallet( $order, $wallet, $mer_id, (string) $transaction_id, $amount, $query_result );
			}
			return new \WP_Error(
				'process_refund_request',
				__( 'This payment type cannot be refunded from WooCommerce. Please refund it in the PAYUNi merchant dashboard.', 'moksa-for-woocommerce' )
			);
		}

		$trade = array(
			'MerID'   => $mer_id,
			'TradeNo' => $transaction_id,
		);

		// 官方「交易請退款」決策（page 38、39、153、164、172）：
		// 9 未請款、3 請款取消、1 請款申請中（先 CloseType=-1 取消請款）→ 全額取消授權，部分就只請款剩下的金額；
		// 7 請款處理中、2 請款成功 → CloseType=2 退款（7 會先記成預計退款，請款完成後自動轉退款）。
		switch ( $close_status ) {
			case CloseStatus::CAPTURE_UNAPPLY:
			case CloseStatus::CAPTURE_CANCELLED:
			case CloseStatus::CAPTURE_APPLING:
				$close_amt = (float) ( $query_result['CloseAmt'] ?? 0 );
				$base      = $close_amt > 0 ? $close_amt : (float) ( $query_result['TradeAmt'] ?? 0 );
				$remaining = (int) round( $base - (float) $amount );
				// 以 WC 的帳為準：WC 呼叫這裡時這筆退款已存檔，訂單總額−已退就是退完後該請款的金額。
				// 兩邊對不上（例如之前部分退款後請款被取消、CloseAmt 歸零，再退會照原交易金額算）就停下，
				// 不能用統一金流那邊的數字去請款，否則會多扣顧客。
				$expected = (int) round( (float) $order->get_total() - (float) $order->get_total_refunded() );
				if ( $base > 0 && $remaining >= 0 && $remaining !== $expected ) {
					return new \WP_Error(
						'process_refund_request',
						/* translators: 1: amount PAYUNi would still capture, 2: amount WooCommerce expects */
						sprintf( __( 'PAYUNi and WooCommerce disagree on how much is left to capture (PAYUNi: %1$d, WooCommerce: %2$d), so nothing was sent. Check the transaction in the PAYUNi merchant dashboard and refund there if needed.', 'moksa-for-woocommerce' ), $remaining, $expected )
					);
				}
				if ( $base <= 0 || $remaining < 0 ) {
					return new \WP_Error(
						'process_refund_request',
						__( 'The refund amount is more than PAYUNi can still refund for this card payment. Please check the transaction in the PAYUNi merchant dashboard.', 'moksa-for-woocommerce' )
					);
				}
				// 分期（AuthType 2）、銀聯（7）只能全額。
				if ( $remaining > 0 && in_array( (string) ( $query_result['AuthType'] ?? '' ), [ '2', '7' ], true ) ) {
					return new \WP_Error(
						'process_refund_request',
						__( 'Installment and UnionPay card payments can only be refunded in full.', 'moksa-for-woocommerce' )
					);
				}

				if ( CloseStatus::CAPTURE_APPLING === $close_status ) {
					$step = self::call_trade_api( 'close', $trade + [ 'CloseType' => -1 ] );
					if ( ! $step['ok'] ) {
						return self::refund_failed( $order, $step );
					}
				}

				if ( 0 === $remaining ) {
					$result = self::call_trade_api( 'cancel', $trade );
					$done   = __( 'PAYUNi cancelled the card authorisation, so the customer is not charged.', 'moksa-for-woocommerce' );
				} else {
					$result = self::call_trade_api(
						'close',
						$trade + [
							'CloseType' => 1,
							'TradeAmt'  => $remaining,
						]
					);
					/* translators: %s: amount that will be captured */
					$done = sprintf( __( 'PAYUNi will now capture only %s from the card.', 'moksa-for-woocommerce' ), wc_price( $remaining, [ 'currency' => $order->get_currency() ] ) );
				}

				if ( ! $result['ok'] ) {
					$restore_note = '';
					if ( CloseStatus::CAPTURE_APPLING === $close_status && $result['unknown'] ) {
						// 結果未知時不重送（可能已經取消授權），只提醒店長確認。
						$restore_note = __( 'The capture request had already been cancelled. If PAYUNi still shows this payment as not captured, capture or cancel it in the PAYUNi merchant dashboard within 3 days of payment.', 'moksa-for-woocommerce' );
					} elseif ( CloseStatus::CAPTURE_APPLING === $close_status ) {
						// 取消請款成功、下一步失敗：把原本的請款送回去，避免這筆授權沒人請款。
						$restore      = self::call_trade_api(
							'close',
							$trade + [
								'CloseType' => 1,
								'TradeAmt'  => (int) round( $base ),
							]
						);
						$restore_note = $restore['ok']
							? __( 'The original capture request was sent again, so nothing has changed.', 'moksa-for-woocommerce' )
							: __( 'The capture request was cancelled and could not be sent again. This payment is now not captured: capture or cancel it in the PAYUNi merchant dashboard within 3 days of payment.', 'moksa-for-woocommerce' );
					}
					if ( CloseStatus::CAPTURE_APPLING === $close_status && ( $result['unknown'] || empty( $restore['ok'] ) ) ) {
						// 這筆現在可能沒有請款：列進待處理、擋後續退款、寄信，不能只留一則備註。
						\Moksafowo\Refund\Guard::flag( $order, (string) $amount, $restore_note );
						self::$refund_flagged = true;
					}
					return self::refund_failed( $order, $result, $restore_note );
				}
				break;

			case CloseStatus::CAPTURE_PROCESSING:
			case CloseStatus::CAPTURE_OK:
				$result = self::call_trade_api(
					'close',
					$trade + [
						'CloseType' => 2,
						'TradeAmt'  => $amount,
					]
				);
				if ( ! $result['ok'] ) {
					return self::refund_failed( $order, $result );
				}
				$done = CloseStatus::CAPTURE_PROCESSING === $close_status
					? __( 'PAYUNi recorded it as a scheduled refund; it is refunded once the capture completes.', 'moksa-for-woocommerce' )
					: '';
				break;

			default:
				/* translators: %s: PAYUNi settlement status */
				$order->add_order_note( sprintf( __( 'This order cannot be refunded in its current state (PAYUNi settlement status: %s)', 'moksa-for-woocommerce' ), $close_status ) );
				return new \WP_Error(
					'process_refund_request',
					/* translators: 1: trade status, 2: close status */
					sprintf( __( 'This order cannot be refunded (transaction status: %1$s, settlement status: %2$s)', 'moksa-for-woocommerce' ), $trade_status, $close_status ),
					array(
						'order_id'      => $order_id,
						'refund_amount' => $amount,
					)
				);
		}

		// 官方要求處理完再查一次，不要只看 API 回 SUCCESS。
		$after = self::query( $order_id, false );
		$order->add_order_note(
			\Moksafowo\Order\Note::build(
				/* translators: 1: refund amount, 2: PAYUNi message */
				sprintf( __( 'PAYUNi refunded %1$s: %2$s', 'moksa-for-woocommerce' ), $amount, $result['message'] ),
				[
					__( 'Settlement status', 'moksa-for-woocommerce' ) => is_array( $after ) && isset( $after['CloseStatus'] ) ? CloseStatus::get_name( (string) $after['CloseStatus'] ) : '',
					__( 'Captured amount', 'moksa-for-woocommerce' ) => is_array( $after ) ? (string) ( $after['CloseAmt'] ?? '' ) : '',
					__( 'Note', 'moksa-for-woocommerce' ) => $done,
				]
			)
		);
		return true;
	}

	/**
	 * 愛金卡、AFTEE、LINE Pay、街口退款（各自的 trade/common/refund/* API）。
	 *
	 * @param \WC_Order           $order          訂單。
	 * @param string              $path           WALLET_REFUND_PATHS 的路徑。
	 * @param string              $mer_id         商店代號。
	 * @param string              $transaction_id UNi 序號。
	 * @param mixed               $amount         這次退款金額。
	 * @param array<string,mixed> $query_result   退款前查單結果。
	 * @return true|\WP_Error
	 */
	private static function refund_wallet( \WC_Order $order, string $path, string $mer_id, string $transaction_id, $amount, array $query_result ) {
		$amt = (int) round( (float) $amount );
		if ( $amt <= 0 || abs( (float) $amount - $amt ) >= 0.01 ) {
			return new \WP_Error( 'process_refund_request', __( 'The refund amount must be a whole number greater than 0.', 'moksa-for-woocommerce' ) );
		}
		$trade_amt = (int) round( (float) ( $query_result['TradeAmt'] ?? 0 ) );
		if ( $trade_amt > 0 && $amt > $trade_amt ) {
			return new \WP_Error( 'process_refund_request', __( 'The refund amount is more than the payment amount.', 'moksa-for-woocommerce' ) );
		}
		// 愛金卡只能全額（page 72）：WC 呼叫這裡時這筆退款已存檔，退完要剛好是 0，而且之前沒退過。
		if ( 'common/refund/icash' === $path ) {
			$left = (int) round( (float) $order->get_total() - (float) $order->get_total_refunded() );
			if ( 0 !== $left || ( $trade_amt > 0 && $amt !== $trade_amt ) ) {
				return new \WP_Error( 'process_refund_request', __( 'icash Pay payments can only be refunded in full, in one go.', 'moksa-for-woocommerce' ) );
			}
		}

		$result = self::call_trade_api(
			$path,
			[
				'MerID'    => $mer_id,
				'TradeNo'  => $transaction_id,
				'TradeAmt' => $amt,
			]
		);
		if ( ! $result['ok'] ) {
			return self::refund_failed( $order, $result );
		}

		$order->add_order_note(
			\Moksafowo\Order\Note::build(
				/* translators: 1: refund amount, 2: PAYUNi message */
				sprintf( __( 'PAYUNi refunded %1$s: %2$s', 'moksa-for-woocommerce' ), $amt, $result['message'] ),
				[
					__( 'Payment type', 'moksa-for-woocommerce' )  => PayType::get_name( (int) ( $query_result['PaymentType'] ?? 0 ) ),
					__( 'Refund number', 'moksa-for-woocommerce' ) => (string) ( $result['data']['RefundNo'] ?? '' ),
					__( 'Refunded at', 'moksa-for-woocommerce' )   => (string) ( $result['data']['RefundDT'] ?? '' ),
				]
			)
		);
		return true;
	}

	/**
	 * 呼叫統一金流 trade/ 底下的 API（Version 1.0）：close、cancel、common/refund/*。
	 *
	 * @param string              $path   trade/ 之後的路徑。
	 * @param array<string,mixed> $fields MerID、TradeNo 與該 API 的欄位（Timestamp 會自動加）。
	 * @return array{ok:bool,unknown:bool,status:string,message:string,data:array<string,string>}
	 */
	private static function call_trade_api( string $path, array $fields ): array {
		$fields['Timestamp'] = time();
		$url                 = ( Credentials::test_mode_enabled() ? 'https://sandbox-api.payuni.com.tw/api/trade/' : 'https://api.payuni.com.tw/api/trade/' ) . $path;
		PayuniPayment::log( 'refund url:' . $url . ' encrypt_info:' . wc_print_r( self::redact_for_log( $fields ), true ) );

		$encrypted = PayuniPayment::encrypt( $fields );
		$response  = wp_remote_post(
			$url,
			array(
				'httpversion' => '1.1',
				'timeout'     => '30',
				'body'        => array(
					'MerID'       => $fields['MerID'],
					'Version'     => '1.0',
					'EncryptInfo' => $encrypted,
					'HashInfo'    => PayuniPayment::hash_info( $encrypted ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			// 連線中斷時統一金流那邊可能已經處理了，結果未知。
			PayuniPayment::log( 'refund error:' . $response->get_error_message() );
			return [
				'ok'      => false,
				'unknown' => true,
				'status'  => '',
				'message' => '',
				'data'    => [],
			];
		}

		$body = wp_remote_retrieve_body( $response );
		PayuniPayment::log( 'refund response body:' . wc_print_r( $body, true ) );
		// 502、HTML 錯誤頁或只帶 Status／Message 的錯誤回應都沒有 EncryptInfo，不能直接解密。
		$result    = json_decode( $body, true );
		$result    = is_array( $result ) ? $result : [];
		$decrypted = is_string( $result['EncryptInfo'] ?? null ) ? (array) PayuniPayment::decrypt( $result['EncryptInfo'] ) : [];
		$status    = (string) ( $result['Status'] ?? '' );
		$message   = sanitize_text_field( (string) ( $decrypted['Message'] ?? ( $result['Message'] ?? '' ) ) );

		return [
			'ok'      => 'SUCCESS' === $status,
			'unknown' => '' === $status,
			'status'  => $status,
			'message' => $message,
			'data'    => array_map( 'sanitize_text_field', array_filter( $decrypted, 'is_scalar' ) ),
		];
	}

	/**
	 * 退款失敗：留備註並讓 WC 撤銷這筆退款紀錄。結果未知時提醒先查再重試，避免退兩次。
	 *
	 * @param \WC_Order                                                $order  訂單。
	 * @param array{ok:bool,unknown:bool,status:string,message:string} $result 失敗那一步的結果。
	 * @param string                                                   $extra  接在訊息後面的說明。
	 * @return \WP_Error
	 */
	private static function refund_failed( \WC_Order $order, array $result, string $extra = '' ): \WP_Error {
		if ( $result['unknown'] ) {
			$message = __( 'Could not reach PAYUNi to refund this order. PAYUNi may still have processed it, so please check the transaction in the PAYUNi merchant dashboard before trying again.', 'moksa-for-woocommerce' );
		} else {
			/* translators: 1: PAYUNi error message, 2: status code */
			$message = sprintf( __( 'The PAYUNi refund failed: %1$s (status %2$s)', 'moksa-for-woocommerce' ), $result['message'], $result['status'] );
		}
		if ( '' !== $extra ) {
			$message .= ' ' . $extra;
		}
		$order->add_order_note( $message );
		// 結果未知（斷線、空白或看不懂的回應）：統一金流可能已經處理，擋後續退款、列進待處理。
		// Guard 只認得斷線和 5xx，200 空白回應或不經過後台按鈕（REST、MCP）的退款要在這裡記。
		if ( $result['unknown'] && ! self::$refund_flagged ) {
			\Moksafowo\Refund\Guard::flag( $order, self::$refund_amount, $message );
		}
		return new \WP_Error( 'process_refund_request', $message, array( 'order_id' => $order->get_id() ) );
	}

	/**
	 * 信用卡幕後 Token 交易（/api/credit v1.3）：用首期取得的 CreditHash 扣款，顧客不在場。
	 *
	 * @return array{status:string,info:array<string,mixed>,message:string}
	 */
	public static function charge_token( \WC_Order $order, string $credit_hash, string $notify_url ): array {
		$prod_desc = array();
		foreach ( $order->get_items() as $item ) {
			$prod_desc[] = $item->get_name() . ' * ' . $item->get_quantity();
		}
		$encrypt_info = array_merge(
			array(
				'MerID'      => Credentials::merchant_id(),
				'MerTradeNo' => PayuniPayment::build_payuni_order_no( $order->get_id() ),
				'TradeAmt'   => (int) ceil( (float) $order->get_total() ),
				'Timestamp'  => time(),
				'ProdDesc'   => mb_substr( implode( ';', $prod_desc ), 0, 550 ),
				'UsrMail'    => $order->get_billing_email(),
				'CreditHash' => $credit_hash,
				'NotifyURL'  => $notify_url,
			),
			PayuniPayment::$einvoice_enabled ? self::renewal_invoice_args( $order ) : array()
		);
		PayuniPayment::log( 'credit token encrypt info:' . wc_print_r( self::redact_for_log( array_diff_key( $encrypt_info, array( 'CreditHash' => '' ) ) ), true ) );

		$encrypted = PayuniPayment::encrypt( $encrypt_info );
		$url       = Credentials::test_mode_enabled() ? 'https://sandbox-api.payuni.com.tw/api/credit' : 'https://api.payuni.com.tw/api/credit';
		$response  = wp_remote_post(
			$url,
			array(
				'httpversion' => '1.1',
				'timeout'     => 70, // 官方：60 秒等不到銀行回應才回 UNKNOWN。
				'headers'     => array( 'User-Agent' => 'payuni' ),
				'body'        => array(
					'MerID'       => Credentials::merchant_id(),
					'Version'     => '1.3',
					'EncryptInfo' => $encrypted,
					'HashInfo'    => PayuniPayment::hash_info( $encrypted ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			PayuniPayment::log( 'credit token error:' . $response->get_error_message() );
			return array(
				'status'   => 'UNKNOWN',
				'info'     => array(),
				'verified' => false,
				'message'  => $response->get_error_message(),
			);
		}

		$http     = (int) wp_remote_retrieve_response_code( $response );
		$result   = json_decode( wp_remote_retrieve_body( $response ), true );
		$result   = is_array( $result ) ? $result : array();
		$info     = array();
		$verified = false;
		if ( 200 === $http && is_string( $result['EncryptInfo'] ?? null ) && isset( $result['HashInfo'] ) && hash_equals( PayuniPayment::hash_info( $result['EncryptInfo'] ), strtoupper( (string) $result['HashInfo'] ) ) ) {
			$info     = (array) PayuniPayment::decrypt( $result['EncryptInfo'] );
			$info     = map_deep( $info, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
			$verified = ! empty( $info );
		}
		PayuniPayment::log( 'credit token response:' . wc_print_r( self::redact_for_log( array_diff_key( $info, array( 'CreditHash' => '' ) ) ), true ) );

		return array(
			'status'   => (string) ( $result['Status'] ?? '' ),
			'info'     => $info,
			// 回應有驗章、解得開，內容才可信；否則（502、HTML 錯誤頁、驗章失敗）結果不明。
			'verified' => $verified,
			'message'  => (string) ( $info['Message'] ?? ( $result['Message'] ?? '' ) ),
		);
	}

	/**
	 * 續扣也要開 PAYUNi 發票（p522 CarrierType／CarrierInfo／InvBuyerName）。首期在 UPP 頁由顧客填，
	 * 續扣沒有顧客在場：有本外掛結帳發票欄位的資料就照用，否則開到 PAYUNi 會員載具（amego，以 UsrMail 為載具）。
	 *
	 * @return array<string,string>
	 */
	private static function renewal_invoice_args( \WC_Order $order ): array {
		$type    = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::INVOICE_TYPE );
		$carrier = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::INVOICE_CARRIER_TYPE );
		$number  = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::INVOICE_CARRIER_NUM );
		$name    = trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		$args    = array( 'InvBuyerName' => '' !== $name ? $name : (string) $order->get_billing_email() );
		$ubn     = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::INVOICE_BUYER_UBN );
		$love    = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::INVOICE_LOVE_CODE );
		if ( 'b2b' === $type && '' !== $ubn ) {
			$args['CarrierType'] = 'Company';
			$args['CarrierInfo'] = $ubn;
			$buyer               = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::INVOICE_BUYER_NAME );
			if ( '' !== $buyer ) {
				$args['InvBuyerName'] = $buyer;
			}
		} elseif ( 'b2c_donate' === $type && '' !== $love ) {
			$args['CarrierType'] = 'Donate';
			$args['CarrierInfo'] = $love;
		} elseif ( in_array( $carrier, array( 'mobile', '3' ), true ) && '' !== $number ) {
			$args['CarrierType'] = '3J0002';
			$args['CarrierInfo'] = $number;
		} elseif ( in_array( $carrier, array( 'cert', '2' ), true ) && '' !== $number ) {
			$args['CarrierType'] = 'CQ0001';
			$args['CarrierInfo'] = $number;
		} else {
			$args['CarrierType'] = 'amego';
		}
		return $args;
	}

	/** 信用卡 Token 取消（p41 /api/credit_bind/cancel，UseTokenType=1 綁定、BindVal=CreditHash）。 */
	public static function cancel_token( string $credit_hash ): bool {
		$encrypt_info = array(
			'MerID'           => Credentials::merchant_id(),
			'UseTokenType'    => 1,
			'BindVal'         => $credit_hash,
			'CreditTokenType' => 2,
			'Timestamp'       => time(),
		);
		$encrypted    = PayuniPayment::encrypt( $encrypt_info );
		$url          = Credentials::test_mode_enabled() ? 'https://sandbox-api.payuni.com.tw/api/credit_bind/cancel' : 'https://api.payuni.com.tw/api/credit_bind/cancel';
		$response     = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'User-Agent' => 'payuni' ),
				'body'    => array(
					'MerID'       => Credentials::merchant_id(),
					'Version'     => '1.0',
					'EncryptInfo' => $encrypted,
					'HashInfo'    => PayuniPayment::hash_info( $encrypted ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$result = json_decode( wp_remote_retrieve_body( $response ), true );
		PayuniPayment::log( 'credit token cancel status:' . ( is_array( $result ) ? (string) ( $result['Status'] ?? '' ) : '' ) );
		return is_array( $result ) && 'SUCCESS' === ( $result['Status'] ?? '' );
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
		if ( '' === (string) $payuni_order_no ) {
			// 這版之前建立、又沒收到通知的訂單沒存 MerTradeNo，用序號還原（格式見 build_payuni_order_no）。
			$serial          = (int) $order->get_meta( OrderMeta::ORDER_SERIAL_NO );
			$payuni_order_no = $order->get_id() . '-' . max( 1, $serial );
		}

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

		$result = json_decode( $response_body, true );
		if ( ! is_array( $result ) || ! is_string( $result['EncryptInfo'] ?? null ) ) {
			PayuniPayment::log( 'PAYUNi query: unexpected response.' );
			return false;
		}
		$decrypted = PayuniPayment::decrypt( $result['EncryptInfo'] );
		PayuniPayment::log( 'query decrypted info:' . wc_print_r( self::redact_for_log( $decrypted ), true ) );

		if ( 'SUCCESS' === $result['Status'] ) {
			if ( ! isset( $decrypted['Result'] ) || ! is_array( $decrypted['Result'] ) || empty( $decrypted['Result'] ) ) {
				PayuniPayment::log( 'PAYUNi query returned SUCCESS but no Result data found.' );
				if ( $add_note ) {
					$order->add_order_note( __( 'PAYUNi responded successfully but returned no order data.', 'moksa-for-woocommerce' ) );
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
			$query_result['TradeAmt']    = $decrypted['Result'][0]['TradeAmt'] ?? '';

			if ( '1' === $query_result['PaymentType'] ) {
				$query_result['CloseStatus'] = $decrypted['Result'][0]['CloseStatus'];
				$query_result['CloseAmt']    = $decrypted['Result'][0]['CloseAmt'] ?? '';
				$query_result['AuthType']    = $decrypted['Result'][0]['AuthType'] ?? '';
			}

			$woo_order_id = PayuniPayment::parse_payuni_order_no_to_woo_order_id( $query_result['MerTradeNo'] );

			if ( $add_note ) {
				$order = wc_get_order( $woo_order_id );
				$row   = $decrypted['Result'][0];
				$order->add_order_note(
					\Moksafowo\Order\Note::build(
						/* translators: %s: transaction status */
						sprintf( __( 'PAYUNi transaction lookup: %s', 'moksa-for-woocommerce' ), TradeStatus::get_name( (string) $query_result['TradeStatus'], $order->get_payment_method() ) ),
						[
							__( 'Payment type', 'moksa-for-woocommerce' )          => PayType::get_name( $query_result['PaymentType'] ),
							__( 'Amount', 'moksa-for-woocommerce' )                => $row['TradeAmt'] ?? '',
							__( 'Paid at', 'moksa-for-woocommerce' )               => $query_result['PaymentDay'] ?? '',
							__( 'Settlement status', 'moksa-for-woocommerce' )     => isset( $query_result['CloseStatus'] ) ? CloseStatus::get_name( (string) $query_result['CloseStatus'] ) : '',
							__( 'PAYUNi transaction ID', 'moksa-for-woocommerce' ) => $query_result['TradeNo'] ?? '',
						]
					)
				);
			}
			PayuniPayment::log( 'PAYUNi query success. Status:' . $result['Status'] . ', Message:' . $decrypted['Message'] . ', Trade Status:' . $query_result['TradeStatus'] );
			return $query_result;
		} else {
			if ( $add_note ) {
				$order->add_order_note(
					\Moksafowo\Order\Note::build(
						/* translators: %s: PAYUNi message */
						sprintf( __( 'The PAYUNi transaction lookup failed: %s', 'moksa-for-woocommerce' ), (string) ( $decrypted['Message'] ?? '' ) ),
						[ __( 'Status code', 'moksa-for-woocommerce' ) => $result['Status'] ?? '' ]
					)
				);
			}
			PayuniPayment::log( 'PAYUNi query failed. Status:' . $result['Status'] . ', Message:' . $decrypted['Message'] );
			return false;
		}
	}

	public function set_gateway( $gateway ) {
		$this->gateway = $gateway;
	}
}
