<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay\Api;

use Exception;
use MoksaWeb\Mowc\Modules\Linepay\Constants;
use MoksaWeb\Mowc\Modules\Linepay\LinePay;
use MoksaWeb\Mowc\Modules\Linepay\StatusCode;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class PaymentRequest {

	protected $gateway;

	public function __construct( $gateway ) {
		$this->gateway = $gateway;
		add_action( 'linepay_process_confirm_failed', array( $this, 'on_process_confirm_failed' ), 10, 1 );
	}

	public function request( $order_id ) {

		try {

			$order        = wc_get_order( $order_id );
			$product_info = array( 'packages' => $this->get_product_info( $order ) );
			$order_id     = $order->get_id();
			$currency     = $order->get_currency();
			$std_amount   = Currency::get_standardized( $order->get_total(), $currency );

			if ( ! Currency::valid_currency_scale( $std_amount ) ) {
				throw new Exception( sprintf( Constants::LOG_TEMPLATE_RESERVE_UNVALID_CURRENCY_SCALE, $order_id, $std_amount, $currency, Currency::get_currency_scale( $currency ), Currency::get_amount_precision( $std_amount ) ) );
			}

			$body = array(
				'orderId'  => $order_id,
				'amount'   => $std_amount,
				'currency' => $currency,
			);

			$confirm_token = self::callback_token( $order_id, Constants::REQUEST_TYPE_CONFIRM );
			$cancel_token  = self::callback_token( $order_id, Constants::REQUEST_TYPE_CANCEL );

			$redirect_urls = array(
				'redirectUrls' => array(
					'confirmUrl'     => esc_url_raw(
						add_query_arg(
							array(
								'request_type' => Constants::REQUEST_TYPE_CONFIRM,
								'order_id'     => $order_id,
								'mo_token'     => $confirm_token,
							),
							WC()->api_request_url( 'linepay_payment' )
						)
					),
					'confirmUrlType' => Constants::CONFIRM_URLTYPE_CLIENT,
					'cancelUrl'      => esc_url_raw(
						add_query_arg(
							array(
								'request_type' => Constants::REQUEST_TYPE_CANCEL,
								'order_id'     => $order_id,
								'mo_token'     => $cancel_token,
							),
							WC()->api_request_url( 'linepay_payment' )
						)
					),
				),
			);

			$options = array(
				'options' => array(
					'payment' => array(
						'payType' => strtoupper( $this->gateway->payment_type ),
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Mo_LinePay_ is wpbrewer fork BC prefix per CLAUDE.md fork-then-patch.
						'capture' => apply_filters( 'Mo_LinePay_payment_capture', true ),
					),
					'extra'   => array(
						'branchName' => '',
					),
				),
			);

			$url = Url::request_url( Constants::REQUEST_TYPE_REQUEST );
			LinePay::log( sprintf( '[request][order_id:%s] http request url : %s', $order_id, $url ) );

			$body         = array_merge( $body, $product_info, $redirect_urls, $options );
			$request_args = $this->build_execute_request_args( $url, $body );
			LinePay::log( sprintf( '[request][order_id:%s] execute request_args: %s', $order_id, wc_print_r( Signature::redact_request_args( $request_args ), true ) ) );

			$result = $this->execute( $url, $request_args );
			LinePay::log( '[request] execute result: ' . wc_print_r( $result, true ) );

			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( '0000' !== $result->returnCode ) {
				throw new Exception( sprintf( 'Execute LINE Pay Request API failed. Return code: %s. Response message: %s', $result->returnCode, $result->returnMessage ) );
			}

			$order->update_meta_data( '_linepay_payment_status', Constants::PAYMENT_STATUS_RESERVED );
			$order->update_meta_data( '_linepay_reserved_transaction_id', $result->info->transactionId );
			$order->save();

			$this->check_payment_and_update_order_note( $order, 'Check payment status after requested' );

			return array(
				'result'   => 'success',
				'redirect' => $result->info->paymentUrl->web,
			);
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		} catch ( Exception $e ) {

			LinePay::log( 'process payment request error:' . $e->getMessage(), 'error' );

			wc_add_wp_error_notices( new WP_Error( 'process_payment_request', __( '[LINE Pay] Order Received but unable to process payment request. Please try to pay again.', 'mo-ectools' ) ) );

			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( false ),
			);
		}
	}

	public function confirm( $order_id, $is_checkout = true ) {

		try {

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				throw new Exception( 'Cant find order by order_id:' . $order_id );
			}

			if ( wc_string_to_bool( $order->get_meta( '_mo_linepay_confirmed' ) ) ) {
				$payment_status = $order->get_meta( '_linepay_payment_status' );
				if ( Constants::PAYMENT_STATUS_CONFIRMED === $payment_status ) {
					LinePay::log( sprintf( '[confirm][order_id:%s] Already confirmed, skipping duplicate call', $order_id ) );
					if ( $is_checkout ) {
						wp_safe_redirect( $this->get_return_url( $order ) );
						exit;
					}
					return true;
				}
				LinePay::log( sprintf( '[confirm][order_id:%s] Confirmation in progress, waiting...', $order_id ) );
			}

			$amount   = $order->get_total();
			$currency = $order->get_currency();

			$reserved_std_amount = Currency::get_standardized( $order->get_total(), $currency );
			$std_amount          = Currency::get_standardized( $amount );

			if ( $std_amount !== $reserved_std_amount ) {
				throw new Exception( sprintf( Constants::LOG_TEMPLATE_CONFIRM_FAILURE_MISMATCH_ORDER_AMOUNT, $std_amount, $reserved_std_amount ) );
			}

			$order->update_meta_data( '_mo_linepay_confirmed', true );
			$order->save();

			$reserved_transaction_id = $order->get_meta( '_linepay_reserved_transaction_id' );
			$url                     = Url::request_url( Constants::REQUEST_TYPE_CONFIRM, array( 'transaction_id' => $reserved_transaction_id ) );
			LinePay::log( sprintf( '[confirm][order_id:%s] http request url : %s', $order_id, $url ) );

			$body = array(
				'amount'   => $std_amount,
				'currency' => $currency,
			);

			$request_args = $this->build_execute_request_args( $url, $body );
			LinePay::log( sprintf( '[confirm][order_id:%s] http_request request_args: %s', $order_id, wc_print_r( Signature::redact_request_args( $request_args ), true ) ) );

			$this->check_payment_and_update_order_note( $order, 'Check payment status before confirm' );

			$result = $this->execute( $url, $request_args, 40 );

			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( '0000' !== $result->returnCode ) {
				throw new Exception( sprintf( 'Execute LINE Pay Confirm API failed. Return code: %s. Return Message: %s', $result->returnCode, $result->returnMessage ) );
			}

			$confirmed_amount = 0;
			foreach ( $result->info->payInfo as $item ) {
				$confirmed_amount += $item->amount;
			}

			$order->payment_complete( $result->info->transactionId );

			$std_confirmed_amount = Currency::get_standardized( $confirmed_amount );
			$order->update_meta_data( '_linepay_transaction_balanced_amount', $std_confirmed_amount );
			$order->update_meta_data( '_linepay_payment_status', Constants::PAYMENT_STATUS_CONFIRMED );
			$order->save();
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			$this->check_payment_and_update_order_note( $order, 'Check payment status when confirmed' );

			if ( $is_checkout ) {
				WC()->cart->empty_cart();
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			} else {
				return true;
			}
		} catch ( Exception $e ) {

			LinePay::log( 'process payment confirm error:' . $e->getMessage() );

			if ( strpos( $e->getMessage(), '1172' ) === false ) {
				$order->update_meta_data( '_mo_linepay_confirmed', false );
				$order->save();
			}

			if ( $is_checkout ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Mo_LinePay_ is wpbrewer fork BC prefix per CLAUDE.md fork-then-patch.
				do_action( 'linepay_process_confirm_failed', $order );
			} else {
				throw $e;
			}
		}
	}

	public function on_process_confirm_failed( $order ): void {

		WC()->session->set( 'order_awaiting_payment', false );

		try {

			$check_status = $this->check( $order );
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$check_code = $check_status->returnCode;
			$check_msg  = $check_status->returnMessage;
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$check_info = sprintf( '[confirm][order_id:%s] Check payment status when confirm failed, return code:%s, return message:%s', $order->get_id(), $check_code, $check_msg );
			LinePay::log( $check_info );
			$order->add_order_note( $check_info );

			if ( StatusCode::COMPLETED === $check_code ) {
				$url            = Url::request_url( Constants::REQUEST_TYPE_DETAILS, array( 'transaction_id' => $order->get_meta( '_linepay_reserved_transaction_id' ) ) );
				$request_args   = $this->build_execute_request_args( $url, null, 20, 'GET' );
				$payment_detail = $this->execute( $url, $request_args, 20 );
				if ( $payment_detail ) {
					$order->update_meta_data( '_mo_linepay_confirmed', true );
					$order->update_meta_data( '_linepay_payment_status', Constants::PAYMENT_STATUS_CONFIRMED );
					// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$order->update_meta_data( '_linepay_transaction_balanced_amount', $payment_detail->info->amount );
					$order->save();
					$order->payment_complete( $payment_detail->info->transactionId );
					// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			} elseif ( StatusCode::AUTHED === $check_code ) {
				$order->update_meta_data( '_linepay_payment_status', Constants::PAYMENT_STATUS_AUTHED );
				$order->save();
				$order->update_status( 'on-hold' );
				$order->add_order_note( __( 'LINE Pay 付款已授權，等待確認', 'mo-ectools' ) );

			} elseif ( StatusCode::CANCELLED_EXPIRED === $check_code ) {
				$order->update_meta_data( '_linepay_payment_status', Constants::PAYMENT_STATUS_CANCELLED );
				$order->save();
				$order->update_status( LinePay::$fail_order_status );
				$order->add_order_note( __( 'LINE Pay 付款已取消或過期', 'mo-ectools' ) );

			} elseif ( StatusCode::FAILED === $check_code ) {
				$order->update_meta_data( '_linepay_payment_status', Constants::PAYMENT_STATUS_FAILED );
				$order->save();
				$order->update_status( LinePay::$fail_order_status );
				$order->add_order_note( __( 'LINE Pay 交易失敗', 'mo-ectools' ) );

			} else {
				$order->update_status( 'on-hold' );
				$order->add_order_note(
					sprintf(
						/* translators: %s: LINE Pay check status return code */
						__( 'LINE Pay 確認付款失敗（狀態代碼 %s）', 'mo-ectools' ),
						$check_code
					)
				);
			}
		} catch ( Exception $e ) {
			LinePay::log( 'check status failed, error:' . $e->getMessage(), 'error' );
		} finally {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}
	}

	public function cancel( $order_id ): void {

		$order                   = wc_get_order( $order_id );
		$reserved_transaction_id = $order->get_meta( '_linepay_reserved_transaction_id' );

		WC()->session->set( 'order_awaiting_payment', false );

		LinePay::log( sprintf( Constants::LOG_TEMPLATE_PAYMENT_CANCEL, $order_id, $reserved_transaction_id ) );

		wp_safe_redirect( $this->get_return_url( $order ) );
		exit;
	}

	public function refund( $order_id, $refund_amount, $reason = '' ) {

		$order             = wc_get_order( $order_id );
		$std_refund_amount = Currency::get_standardized( $refund_amount );

		if ( false === $order ) {
			return new WP_Error(
				'process_refund_request',
				sprintf(
					/* translators: %s: WooCommerce order ID being refunded */
					__( 'Unable to find order #%s', 'mo-ectools' ),
					$order_id
				),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $std_refund_amount,
				)
			);
		}

		$transaction_id = $order->get_transaction_id();

		$remaining_refund_amount = $order->get_remaining_refund_amount();
		LinePay::log( 'remaining refund:' . $remaining_refund_amount );
		$is_partial_refund = ( $remaining_refund_amount > 0 ) ? true : false;

		return $this->do_refund( $order, $transaction_id, $std_refund_amount, $is_partial_refund );
	}

	public function check( $order ) {

		$reserved_transaction_id = $order->get_meta( '_linepay_reserved_transaction_id' );

		if ( empty( $reserved_transaction_id ) ) {
			throw new Exception( esc_html__( 'no transaction_id is found', 'mo-ectools' ) );
		}

		$url = Url::request_url( Constants::REQUEST_TYPE_CHECK, array( 'transaction_id' => $reserved_transaction_id ) );
		LinePay::log( sprintf( '[check][order_id:%s] http request url : %s', $order->get_id(), $url ) );

		$request_args = $this->build_execute_request_args( $url, null, 20, 'GET' );
		LinePay::log( sprintf( '[check][order_id:%s] execute request_args: %s', $order->get_id(), wc_print_r( Signature::redact_request_args( $request_args ), true ) ) );

		return $this->execute( $url, $request_args, 20 );
	}

	private function build_execute_request_args( $url, $body = null, $timeout = 20, $method = 'POST' ) {

		$channel_info = LinePay::get_channel_info();
		$request_time = Signature::generate_request_time();

		$request_body = '';
		if ( ! is_null( $body ) ) {
			if ( is_array( $body ) ) {
				$request_body = wp_json_encode( $body );
			} else {
				$request_body = $body;
			}
		}

		$headers = array(
			'content-type'               => 'application/json; charset=UTF-8',
			'X-LINE-ChannelId'           => $channel_info['channel_id'],
			'X-LINE-Authorization-Nonce' => $request_time,
			'X-LINE-Authorization'       => Signature::generate_signature( $channel_info['channel_secret'], $url, $request_body, $request_time, $method ),
		);

		$request_args = array(
			'httpversion' => '1.1',
			'timeout'     => $timeout,
			'headers'     => $headers,
			'method'      => $method,
		);

		if ( is_array( $body ) ) {
			$request_args = array_merge( $request_args, array( 'body' => wp_json_encode( $body ) ) );
		}

		return $request_args;
	}

	private function do_refund( $order, $transaction_id, $refund_amount, $is_partial_refund = false ) {

		$order_id          = $order->get_id();
		$std_refund_amount = Currency::get_standardized( $refund_amount );

		$url  = Url::request_url( Constants::REQUEST_TYPE_REFUND, array( 'transaction_id' => $transaction_id ) );
		$body = array(
			'refundAmount' => $std_refund_amount,
		);

		$request_args = $this->build_execute_request_args( $url, $body );
		LinePay::log( sprintf( '[refund][order_id:%s] request_args:%s', $order_id, wc_print_r( Signature::redact_request_args( $request_args ), true ) ) );

		try {
			$resp = $this->execute( $url, $request_args );

			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( '0000' !== $resp->returnCode ) {
				return new WP_Error( 'error', sprintf( 'Execute LINE Pay Refund API failed. Return code: %s. Return Message: %s', $resp->returnCode, $resp->returnMessage ) );
			}
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} catch ( Exception $e ) {
			LinePay::log( sprintf( '[refund][order_id:%s] refund error:%s', $order_id, $e->getMessage() ) );
			return new WP_Error( 'error', $e->getMessage() );
		}

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( '0000' !== $resp->returnCode ) {
			$msg = sprintf(
				/* translators: 1: refund transaction id, 2: returnCode, 3: returnMessage */
				__( 'Refund via LINE Pay failed. Refund transaction id: %1$s, returnCode: %2$s, returnMessage: %3$s', 'mo-ectools' ),
				isset( $resp->info->refundTransactionId ) ? $resp->info->refundTransactionId : 'n/a',
				$resp->returnCode,
				$resp->returnMessage
			);
			$order->add_order_note( $msg );
			return new WP_Error( 'mowp_linepay_refund_failed', $msg );
		}

		$refund_ids = $order->get_meta( '_linepay_refund_transaction_id' );
		if ( empty( $refund_ids ) ) {
			$refund_ids = array();
		}

		LinePay::log( sprintf( '[refund][order_id:%s] refund transaction ids:%s', $order_id, wc_print_r( $refund_ids, true ) ) );
		$refund_ids[] = $resp->info->refundTransactionId;
		$order->update_meta_data( '_linepay_refund_transaction_id', $refund_ids );

		if ( ! $is_partial_refund ) {
			$order->update_meta_data( '_linepay_payment_status', Constants::PAYMENT_STATUS_REFUNDED );
		}

		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: %s: LINE Pay refund transaction id returned by API */
				__( 'LINE Pay 退款成功（退款編號 %s）', 'mo-ectools' ),
				$resp->info->refundTransactionId
			)
		);
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$this->check_payment_and_update_order_note( $order, 'Check payment status after refunded' );

		return true;
	}

	private function execute( $url, $request_args = null, $timeout = 20 ) {

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( esc_html( 'Execute remote request error:' . $response->get_error_message() ) );
		}

		$http_status   = (int) $response['response']['code'];
		$response_body = Signature::json_custom_decode( wp_remote_retrieve_body( $response ) );
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$return_code = $response_body->returnCode;
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		LinePay::log( '[execute] http response code: ' . $http_status . ', response body: ' . wc_print_r( $response_body, true ) );

		if ( 200 !== $http_status ) {
			throw new Exception( esc_html( sprintf( 'Execute API http response not success. http response code: %s. url: $s', $http_status, $url ) ) );
		}

		return $response_body;
	}

	private function get_product_info( $order ) {
		$packages = array();
		$items    = $order->get_items();

		if ( count( $items ) > 0 ) {

			$products = array();

			$first_item = $items[ array_key_first( $items ) ];
			$wc_product = wc_get_product( $first_item->get_product_id() );

			$order_name = $wc_product->get_name();

			if ( count( $items ) > 1 ) {
				$order_name = apply_filters(
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Mo_LinePay_ is wpbrewer fork BC prefix per CLAUDE.md fork-then-patch.
					'Mo_LinePay_checkout_product_name',
					sprintf(
						/* translators:  %1$s is product name, %2$s is the order item count */
						__( '%1$s and total %2$s products', 'mo-ectools' ),
						$order_name,
						$order->get_item_count()
					)
				);
			}

			$product = array(
				'id'       => $first_item->get_product_id(),
				'name'     => sanitize_text_field( $order_name ),
				'quantity' => 1,
				'price'    => Currency::get_standardized( $order->get_total() ),
			);

			$thumbnail_image_urls = wp_get_attachment_image_src( get_post_thumbnail_id( $first_item->get_product_id() ) );

			if ( isset( $thumbnail_image_urls[0] ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Mo_LinePay_ is wpbrewer fork BC prefix per CLAUDE.md fork-then-patch.
				$product['imageUrl'] = apply_filters( 'Mo_LinePay_checkout_product_image', $thumbnail_image_urls[0] );
			}

			array_push( $products, $product );

			array_push(
				$packages,
				array(
					'id'       => 'WC-ITEMS||' . $order->get_id(),
					'name'     => sanitize_text_field( 'WC_ITEMS' ),
					'amount'   => Currency::get_standardized( $order->get_total() ),
					'products' => $products,
				)
			);
		}

		return $packages;
	}

	private function check_payment_and_update_order_note( $order, $context ): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Mo_LinePay_ is wpbrewer fork BC prefix per CLAUDE.md fork-then-patch.
		if ( LinePay::$detail_payment_status_note_enabled || apply_filters( 'Mo_LinePay_enable_detail_note', false ) ) {
			$check_status = $this->check( $order );
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$check_code = $check_status->returnCode;
			$check_msg  = $check_status->returnMessage;
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$check_info = sprintf( '[check][order_id:%s] %s, return code:%s, return message:%s', $order->get_id(), $context, $check_code, $check_msg );
			LinePay::log( $check_info );
			$order->add_order_note( $check_info );
		}
	}

	public function get_return_url( $order = null ) {
		if ( $order ) {
			$return_url = $order->get_checkout_order_received_url();
		} else {
			$return_url = wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() );
		}
		return apply_filters( 'woocommerce_get_return_url', $return_url, $order );
	}

	public static function callback_token( $order_id, $request_type ) {
		return Signature::callback_token( $order_id, (string) $request_type );
	}
}
