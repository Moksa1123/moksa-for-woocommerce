<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay\Api;

use Exception;
use MoksaWeb\Mowc\Modules\Linepay\Constants;
use MoksaWeb\Mowc\Modules\Linepay\Gateways\Credit;
use MoksaWeb\Mowc\Modules\Linepay\LinePay;

defined( 'ABSPATH' ) || exit;

final class PaymentResponse {

	private static $instance;

	public static function init(): void {
		self::get_instance();
		add_action( 'woocommerce_api_linepay_payment', array( self::get_instance(), 'receive_payment_response' ) );
	}

	public function receive_payment_response(): void {

		try {

			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$order_id     = isset( $_GET['order_id'] ) ? sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) : '';
			$request_type = isset( $_GET['request_type'] ) ? sanitize_text_field( wp_unslash( $_GET['request_type'] ) ) : '';
			$posted_token = isset( $_GET['mo_token'] ) ? sanitize_text_field( wp_unslash( $_GET['mo_token'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$expected_token = PaymentRequest::callback_token( $order_id, $request_type );
			if ( '' === $posted_token || ! hash_equals( $expected_token, $posted_token ) ) {
				throw new Exception( sprintf( 'LINE Pay callback token mismatch for order_id=%s request_type=%s — request rejected.', $order_id, $request_type ) );
			}

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				throw new Exception( sprintf( Constants::LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_ORDER_ID, $order_id, __( 'Unable to process callback.', 'mo-ectools' ) ) );
			}

			$payment_status = $order->get_meta( '_linepay_payment_status' );

			$gateway = new Credit();
			$request = new PaymentRequest( $gateway );

			if ( Constants::PAYMENT_STATUS_RESERVED === $payment_status ) {

				switch ( $request_type ) {
					case Constants::REQUEST_TYPE_CONFIRM:
						$request->confirm( $order_id );
						break;
					case Constants::REQUEST_TYPE_CANCEL:
						$request->cancel( $order_id );
						break;
				}
			} else {
				LinePay::log( sprintf( 'invalid status: %s to handle callback for order id: %s', $payment_status, $order_id ) );
			}
		} catch ( Exception $e ) {
			LinePay::log( 'receive_payment_response error: ' . $e->getMessage() );
		}
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// do nothing.
	}
}
