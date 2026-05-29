<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay;

use Exception;
use MoksaWeb\Mowc\Logging\Logger;
use MoksaWeb\Mowc\Modules\Linepay\Admin\OrderMetaBox;
use MoksaWeb\Mowc\Modules\Linepay\Api\PaymentRequest;
use MoksaWeb\Mowc\Modules\Linepay\Api\PaymentResponse;
use MoksaWeb\Mowc\Modules\Linepay\Gateways\Credit;

defined( 'ABSPATH' ) || exit;

final class LinePay {

	private static $instance;

	public static $fail_order_status;
	public static $detail_payment_status_note_enabled;
	public static $log_enabled = false;
	public static $log         = false;
	public static $allowed_payments;
	public static $enable_sandbox;
	public static $env_status;
	public static $channel_info;
	public static $currency_scales;

	public static function init(): void {

		self::get_instance();

		self::$fail_order_status                  = get_option( 'Mo_LinePay_payment_fail_order_status', 'wc-failed' );
		self::$detail_payment_status_note_enabled = wc_string_to_bool( get_option( 'Mo_LinePay_detail_status_note_enabled' ) );
		self::$log_enabled                        = 'yes' === get_option( 'Mo_LinePay_debug_log_enabled', 'no' );
		self::$enable_sandbox                     = wc_string_to_bool( get_option( 'Mo_LinePay_sandboxmode_enabled' ) );
		self::$env_status                         = self::$enable_sandbox ? Constants::ENV_SANDBOX : Constants::ENV_REAL;

		self::$channel_info = array(
			Constants::ENV_REAL    => array(
				'channel_id'     => get_option( 'Mo_LinePay_channel_id' ),
				'channel_secret' => get_option( 'Mo_LinePay_channel_secret' ),
			),
			Constants::ENV_SANDBOX => array(
				'channel_id'     => get_option( 'Mo_LinePay_sandbox_channel_id' ),
				'channel_secret' => get_option( 'Mo_LinePay_sandbox_channel_secret' ),
			),
		);

		self::$currency_scales = array(
			'TWD' => 0,
		);

		self::$allowed_payments = array(
			'linepay-tw' => Credit::class,
		);

		PaymentResponse::init();
		OrderMetaBox::init();

		add_filter( 'woocommerce_payment_gateways', array( self::get_instance(), 'add_mo_linepay_payment_gateway' ) );
		add_action( 'wp_enqueue_scripts', array( self::get_instance(), 'enqueue_scripts' ), 9 );
		add_action( 'admin_enqueue_scripts', array( self::get_instance(), 'admin_scripts' ), 9 );

		add_action( 'wp_ajax_linepay_confirm', array( self::get_instance(), 'ajax_confirm_payment' ) );
	}

	public function ajax_confirm_payment(): void {

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mo-ectools' ) ), 403 );
		}

		$nonce = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'linepay-confirm' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unsecure AJAX call', 'mo-ectools' ) ), 403 );
		}

		$order_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'No such order id', 'mo-ectools' ) ), 404 );
		}

		$gateway = new Credit();
		$request = new PaymentRequest( $gateway );

		try {
			if ( $request->confirm( $order->get_id(), false ) ) {
				$order->add_order_note( __( 'LINE Pay 確認付款成功', 'mo-ectools' ) );
				wp_send_json(
					array(
						'success' => true,
						'message' => __( 'Confirm succeed', 'mo-ectools' ),
					)
				);
			}
		} catch ( Exception $e ) {

			$order->add_order_note( __( 'LINE Pay 確認付款失敗：', 'mo-ectools' ) . $e->getMessage() );
			wp_send_json(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				)
			);
		}
	}

	public static function get_channel_info(): array {
		return self::$channel_info[ self::$env_status ];
	}

	public function add_mo_linepay_payment_gateway( $methods ) {
		return array_merge( $methods, self::$allowed_payments );
	}

	public function enqueue_scripts(): void {
		wp_enqueue_style( 'mo-linepay-public', MOWC_PLUGIN_URL . 'src/Modules/Linepay/assets/css/mo-linepay-public.css', array(), MOWC_VERSION );
	}

	public function admin_scripts(): void {

		wp_enqueue_script( 'mo-linepay-admin', MOWC_PLUGIN_URL . 'src/Modules/Linepay/assets/js/mo-linepay-admin.js', array(), MOWC_VERSION, true );
		wp_enqueue_style( 'mo-linepay-admin', MOWC_PLUGIN_URL . 'src/Modules/Linepay/assets/css/mo-linepay-admin.css', array(), MOWC_VERSION );

		wp_localize_script(
			'mo-linepay-admin',
			'mo_linepay',
			array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'confirm_nonce' => wp_create_nonce( 'linepay-confirm' ),
				'confirm_msg'   => __( '確定要對這筆 LINE Pay 訂單請款？此動作不可復原。', 'mo-ectools' ),
				'error_msg'     => __( '連線錯誤，請稍後再試。', 'mo-ectools' ),
			)
		);
	}

	public static function log( $message, string $level = 'info' ): void {
		if ( ! self::$log_enabled ) {
			return;
		}
		$msg    = is_string( $message ) ? $message : (string) wp_json_encode( $message );
		$method = in_array( $level, array( 'info', 'warning', 'error', 'debug' ), true ) ? $level : 'info';
		Logger::{$method}( 'linepay', $msg );
	}

	public function __construct() {
		// do nothing.
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
