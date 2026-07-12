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

		self::$fail_order_status                  = get_option( 'moksafowo_linepay_payment_fail_order_status', 'wc-failed' );
		self::$detail_payment_status_note_enabled = wc_string_to_bool( get_option( 'moksafowo_linepay_detail_status_note_enabled' ) );
		self::$log_enabled                        = 'yes' === get_option( 'moksafowo_linepay_debug_log_enabled', 'no' );
		self::$enable_sandbox                     = wc_string_to_bool( get_option( 'moksafowo_linepay_sandboxmode_enabled' ) );
		self::$env_status                         = self::$enable_sandbox ? Constants::ENV_SANDBOX : Constants::ENV_REAL;

		self::$channel_info = array(
			Constants::ENV_REAL    => array(
				'channel_id'     => get_option( 'moksafowo_linepay_channel_id' ),
				'channel_secret' => get_option( 'moksafowo_linepay_channel_secret' ),
			),
			Constants::ENV_SANDBOX => array(
				'channel_id'     => get_option( 'moksafowo_linepay_sandbox_channel_id' ),
				'channel_secret' => get_option( 'moksafowo_linepay_sandbox_channel_secret' ),
			),
		);

		self::$currency_scales = array(
			'TWD' => 0,
		);

		self::$allowed_payments = array(
			'moksafowo-linepay' => Credit::class,
		);

		PaymentResponse::init();
		OrderMetaBox::init();

		add_filter( 'woocommerce_payment_gateways', array( self::get_instance(), 'add_moksafowo_linepay_payment_gateway' ) );
		add_action( 'wp_enqueue_scripts', array( self::get_instance(), 'enqueue_scripts' ), 9 );
		add_action( 'admin_enqueue_scripts', array( self::get_instance(), 'admin_scripts' ), 9 );

		add_action( 'wp_ajax_moksafowo_linepay_confirm', array( self::get_instance(), 'ajax_confirm_payment' ) );
	}

	public function ajax_confirm_payment(): void {

		check_ajax_referer( 'moksafowo-linepay-confirm', 'security' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( '權限不足。', 'mo-ectools' ) ), 403 );
		}

		$order_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( '找不到此訂單。', 'mo-ectools' ) ), 404 );
		}

		$gateway = new Credit();
		$request = new PaymentRequest( $gateway );

		try {
			if ( $request->confirm( $order->get_id(), false ) ) {
				$order->add_order_note( __( 'LINE Pay 確認付款成功', 'mo-ectools' ) );
				wp_send_json(
					array(
						'success' => true,
						'message' => __( '確認付款成功。', 'mo-ectools' ),
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

	public function add_moksafowo_linepay_payment_gateway( $methods ) {
		return array_merge( $methods, self::$allowed_payments );
	}

	public function enqueue_scripts(): void {
		if ( ! is_checkout() && ! is_cart() && ! is_wc_endpoint_url( 'view-order' ) && ! is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		wp_enqueue_style( 'moksafowo-linepay-public', MOKSAFOWO_PLUGIN_URL . 'src/Modules/Linepay/assets/css/moksafowo-linepay-public.css', array(), MOKSAFOWO_VERSION );
	}

	public function admin_scripts(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders', 'woocommerce_page_wc-settings' ], true ) ) {
			return;
		}

		wp_enqueue_script( 'moksafowo-linepay-admin', MOKSAFOWO_PLUGIN_URL . 'src/Modules/Linepay/assets/js/moksafowo-linepay-admin.js', array(), MOKSAFOWO_VERSION, true );
		wp_enqueue_style( 'moksafowo-linepay-admin', MOKSAFOWO_PLUGIN_URL . 'src/Modules/Linepay/assets/css/moksafowo-linepay-admin.css', array(), MOKSAFOWO_VERSION );

		wp_localize_script(
			'moksafowo-linepay-admin',
			'moksafowo_linepay',
			array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'confirm_nonce' => wp_create_nonce( 'moksafowo-linepay-confirm' ),
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

	public function __construct() {}


	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
