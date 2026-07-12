<?php

namespace Moksafowo\Modules\Payuni;

use Moksafowo\Modules\Payuni\Admin\OrderList;
use Moksafowo\Modules\Payuni\Admin\OrderMetaBoxes;
use Moksafowo\Modules\Payuni\Api\PaymentRequest;
use Moksafowo\Modules\Payuni\Api\PaymentResponse;
use Moksafowo\Modules\Payuni\Gateways\Aftee;
use Moksafowo\Modules\Payuni\Gateways\ApplePay;
use Moksafowo\Modules\Payuni\Gateways\Atm;
use Moksafowo\Modules\Payuni\Gateways\Credit;
use Moksafowo\Modules\Payuni\Gateways\CreditRed;
use Moksafowo\Modules\Payuni\Gateways\ICash;
use Moksafowo\Modules\Payuni\Gateways\JKoPay;
use Moksafowo\Modules\Payuni\Gateways\Unified;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment3;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment6;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment9;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment12;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment18;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment24;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment30;
use Moksafowo\Modules\Payuni\Gateways\CreditUnionPay;
use Moksafowo\Modules\Payuni\Gateways\Cvs;
use Moksafowo\Modules\Payuni\Gateways\GooglePay;
use Moksafowo\Modules\Payuni\Gateways\LinePay;
use Moksafowo\Modules\Payuni\Gateways\SamsungPay;
use Moksafowo\Modules\Payuni\Settings\SettingsTab;
use Moksafowo\Modules\Payuni\Utils\OrderMeta;



defined( 'ABSPATH' ) || exit;


class PayuniPayment {



	private static $instance;


	public static $log_enabled = false;


	public static $log = false;


	public static $allowed_payments;


	public static $available_installments;


	public static $einvoice_enabled;


	public static $order_metas;


	public static $notify_url;


	public function __construct() {}



	public static function init() {

		self::get_instance();

		add_action( 'after_setup_theme', array( self::get_instance(), 'plugin_i18n' ), 20 );
		add_action( 'woocommerce_init', array( self::get_instance(), 'plugin_init' ), 30 );

		add_filter( 'woocommerce_payment_gateways', array( self::get_instance(), 'add_payuni_payment_gateway' ) );
		add_action( 'wp_enqueue_scripts', array( self::get_instance(), 'moksafowo_payuni_checkout_enqueue_scripts' ), 9 );
		add_action( 'admin_enqueue_scripts', array( self::get_instance(), 'moksafowo_payuni_admin_scripts' ), 9 );

		add_action( 'wp_ajax_moksafowo_payuni_query', array( self::get_instance(), 'moksafowo_payuni_ajax_query_payment' ) );
	}

	public function plugin_i18n() {}

	public function plugin_init() {
		self::$log_enabled      = 'yes' === get_option( 'moksafowo_payuni_payment_debug_log_enabled', 'no' );
		self::$einvoice_enabled = 'yes' === get_option( 'moksafowo_payuni_payment_einvoice_enabled', 'no' );

		OrderList::init();
		OrderMetaBoxes::init();
		PaymentResponse::init();

		self::$allowed_payments = array(
			Unified::GATEWAY_ID        => '\Moksafowo\Modules\Payuni\Gateways\Unified',
			Credit::GATEWAY_ID         => '\Moksafowo\Modules\Payuni\Gateways\Credit',
			Cvs::GATEWAY_ID            => '\Moksafowo\Modules\Payuni\Gateways\Cvs',
			Atm::GATEWAY_ID            => '\Moksafowo\Modules\Payuni\Gateways\Atm',
			Aftee::GATEWAY_ID          => '\Moksafowo\Modules\Payuni\Gateways\Aftee',
			ApplePay::GATEWAY_ID       => '\Moksafowo\Modules\Payuni\Gateways\ApplePay',
			GooglePay::GATEWAY_ID      => '\Moksafowo\Modules\Payuni\Gateways\GooglePay',
			SamsungPay::GATEWAY_ID     => '\Moksafowo\Modules\Payuni\Gateways\SamsungPay',
			LinePay::GATEWAY_ID        => '\Moksafowo\Modules\Payuni\Gateways\LinePay',
			CreditUnionPay::GATEWAY_ID => '\Moksafowo\Modules\Payuni\Gateways\CreditUnionPay',
			ICash::GATEWAY_ID          => '\Moksafowo\Modules\Payuni\Gateways\ICash',
			JKoPay::GATEWAY_ID         => '\Moksafowo\Modules\Payuni\Gateways\JKoPay',
			CreditRed::GATEWAY_ID      => '\Moksafowo\Modules\Payuni\Gateways\CreditRed',
		);

		$number_of_payments = get_option( 'moksafowo_payuni_payment_installment_number_of_payments', array() );

		self::$available_installments = array(
			CreditInstallment3::GATEWAY_ID  => '\Moksafowo\Modules\Payuni\Gateways\CreditInstallment3',
			CreditInstallment6::GATEWAY_ID  => '\Moksafowo\Modules\Payuni\Gateways\CreditInstallment6',
			CreditInstallment9::GATEWAY_ID  => '\Moksafowo\Modules\Payuni\Gateways\CreditInstallment9',
			CreditInstallment12::GATEWAY_ID => '\Moksafowo\Modules\Payuni\Gateways\CreditInstallment12',
			CreditInstallment18::GATEWAY_ID => '\Moksafowo\Modules\Payuni\Gateways\CreditInstallment18',
			CreditInstallment24::GATEWAY_ID => '\Moksafowo\Modules\Payuni\Gateways\CreditInstallment24',
			CreditInstallment30::GATEWAY_ID => '\Moksafowo\Modules\Payuni\Gateways\CreditInstallment30',
		);

		foreach ( self::$available_installments as $key => $installment ) {
			if ( in_array( $key, $number_of_payments, true ) ) {
				self::$allowed_payments[ $key ] = $installment;
			}
		}

		self::$order_metas = array(
			OrderMeta::UNI_NO       => __( 'Trade No', 'mo-ectools' ),
			OrderMeta::TRADE_AMOUNT => __( 'Trade Amount', 'mo-ectools' ),
			OrderMeta::TRADE_STATUS => __( 'Trade Status', 'mo-ectools' ),
			OrderMeta::MESSAGE      => __( 'Message', 'mo-ectools' ),
			OrderMeta::PAID_AT      => __( 'Paid At', 'mo-ectools' ),
			OrderMeta::CLOSE_STATUS => __( 'Close Status', 'mo-ectools' ),
			OrderMeta::CLOSE_TIME   => __( 'Close Time', 'mo-ectools' ),
			OrderMeta::CLOSE_AUTH   => __( 'Close Auth', 'mo-ectools' ),
			OrderMeta::REFUND_NO    => __( 'Refund No', 'mo-ectools' ),
			OrderMeta::REFUND_AMT   => __( 'Refund Amount', 'mo-ectools' ),
			OrderMeta::REFUND_TIME  => __( 'Refund Time', 'mo-ectools' ),
		);
	}


	public function add_payuni_payment_gateway( $methods ) {
		if ( ! is_array( self::$allowed_payments ) || empty( self::$allowed_payments ) ) {
			return $methods;
		}

		foreach ( self::$allowed_payments as $id => $class ) {
			if ( ! self::should_register_gateway( $id ) ) {
				continue;
			}
			$methods[ $id ] = $class;
		}
		return $methods;
	}


	public static function should_register_gateway( string $id ): bool {
		$mode    = get_option( 'moksafowo_payuni_display_mode', 'multi' );
		$unified = Gateways\Unified::GATEWAY_ID;

		if ( 'single' === $mode ) {
			return $id === $unified;
		}

		if ( $id === $unified ) {
			return false;
		}

		if ( strpos( $id, 'moksafowo_payuni_installment_' ) === 0 ) {
			return true;
		}

		$allowlist = (array) get_option( 'moksafowo_payuni_enabled_methods', array() );
		return in_array( $id, $allowlist, true );
	}


	public static function moksafowo_payuni_checkout_enqueue_scripts() {

		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_style( 'moksafowo-payuni-public', ( MOKSAFOWO_PLUGIN_URL . 'src/Modules/Payuni/' ) . 'assets/css/styles-public.css', array(), MOKSAFOWO_VERSION, 'all' );

		wp_enqueue_script( 'moksafowo-payuni-public', ( MOKSAFOWO_PLUGIN_URL . 'src/Modules/Payuni/' ) . 'assets/js/scripts.js', array(), MOKSAFOWO_VERSION, true );
	}


	public function moksafowo_payuni_admin_scripts() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders', 'woocommerce_page_wc-settings' ], true ) ) {
			return;
		}

		wp_enqueue_style( 'moksafowo-payuni-admin', ( MOKSAFOWO_PLUGIN_URL . 'src/Modules/Payuni/' ) . 'assets/css/styles-admin.css', array(), MOKSAFOWO_VERSION, 'all' );

		wp_enqueue_script( 'moksafowo-payuni-admin', ( MOKSAFOWO_PLUGIN_URL . 'src/Modules/Payuni/' ) . 'assets/js/scripts-admin.js', array(), MOKSAFOWO_VERSION, true );
		wp_localize_script(
			'moksafowo-payuni-admin',
			'moksafowo_payuni',
			array(
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'query_nonce' => wp_create_nonce( 'moksafowo-payuni-query' ),
				'error_msg'   => __( '連線錯誤，請稍後再試。', 'mo-ectools' ),
			)
		);
	}


	public function moksafowo_payuni_ajax_query_payment() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- AJAX handler; wp_verify_nonce() called at method entry; nonce token raw read is intentional.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( '權限不足。', 'mo-ectools' ) ), 403 );
		}
		if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_POST['security'] ) ), 'moksafowo-payuni-query' ) ) {
			wp_send_json_error( array( 'message' => __( '安全驗證失敗，請重新整理後再試。', 'mo-ectools' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( '找不到此訂單。', 'mo-ectools' ) ), 404 );
		}

		$reserved_transaction_id = $order->get_transaction_id();
		$request                 = new PaymentRequest();
		try {

			if ( $request->query( $order->get_id() ) !== false ) {
				$return = array(
					'success' => true,
					'message' => __( 'PAYUNi 訂單查詢成功。', 'mo-ectools' ),
				);
				wp_send_json( $return );
			} else {
				$return = array(
					'success' => false,
					'message' => __( 'PAYUNi 訂單查詢失敗，詳情請見訂單備註。', 'mo-ectools' ),
				);
				wp_send_json( $return );
			}
		} catch ( \Exception $e ) {

			$order->add_order_note( __( 'PAYUNi 訂單查詢失敗：', 'mo-ectools' ) . $e->getMessage() );
			$return = array(
				'success' => false,
				'message' => $e->getMessage(),
			);
			wp_send_json( $return );

		}
	}


	public static function encrypt( $encrypt_info ) {

		$hashkey = Credentials::hashkey();
		$hashiv  = Credentials::hashiv();

		$tag       = '';
		$encrypted = openssl_encrypt( http_build_query( $encrypt_info ), 'aes-256-gcm', $hashkey, 0, $hashiv, $tag );
		if ( false === $encrypted ) {
			throw new \RuntimeException( 'PAYUNi AES-256-GCM encrypt failed' );
		}
		// Wire format: bin2hex(ciphertext . ':::' . base64(tag)) — PAYUNi spec; strrpos avoids
		// misparse when raw ciphertext bytes happen to contain ':::'.
		return trim( bin2hex( $encrypted . ':::' . base64_encode( $tag ) ) );
	}


	public static function decrypt( string $encrypt_str = '' ) {

		$hashkey = Credentials::hashkey();
		$hashiv  = Credentials::hashiv();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- remote hex (EncryptInfo) — malformed input returns false, validated below; @ suppresses the warning so the hex2bin return value can be validated explicitly.
		$blob = @hex2bin( $encrypt_str );
		if ( false === $blob ) {
			return [];
		}
		$sep_pos = strrpos( $blob, ':::' );
		if ( false === $sep_pos ) {
			return [];
		}
		$encrypt_data = substr( $blob, 0, $sep_pos );
		$tag          = base64_decode( substr( $blob, $sep_pos + 3 ), true );
		if ( false === $tag ) {
			return [];
		}
		$encrypt_info = openssl_decrypt( $encrypt_data, 'aes-256-gcm', $hashkey, 0, $hashiv, $tag );
		if ( false === $encrypt_info ) {
			return [];
		}
		parse_str( $encrypt_info, $encrypt_arr );
		return $encrypt_arr;
	}


	public static function hash_info( string $encrypt_str = '' ) {
		return strtoupper( hash( 'sha256', Credentials::hashkey() . $encrypt_str . Credentials::hashiv() ) );
	}


	public static function build_payuni_order_no( $order_id ) {

		$order = wc_get_order( $order_id );

		$payuni_order_no = $order_id;

		$order_serial_no = $order->get_meta( OrderMeta::ORDER_SERIAL_NO );

		if ( $order_serial_no && $order_serial_no < 999 ) {
			++$order_serial_no;
			$payuni_order_no = $payuni_order_no . '-' . $order_serial_no;
		} else {
			$order_serial_no = 1;
			$payuni_order_no = $payuni_order_no . '-' . $order_serial_no;
		}

		$order->update_meta_data( OrderMeta::ORDER_SERIAL_NO, $order_serial_no );
		$order->save();

		return $payuni_order_no;
	}


	public static function parse_payuni_order_no_to_woo_order_id( $payuni_order_no ) {

		if ( strpos( $payuni_order_no, '-' ) !== false ) {
			return explode( '-', $payuni_order_no )[0];
		}

		return $payuni_order_no;
	}


	public static function get_refund_api_url( $payment_method ) {

		$base_api_url = Credentials::test_mode_enabled() ? 'https://sandbox-api.payuni.com.tw/api' : 'https://api.payuni.com.tw/api';

		if ( 'moksafowo-payuni-credit' === $payment_method || 'moksafowo-payuni-applepay' === $payment_method ) {
			$base_api_url .= '/trade/cancel';
		} elseif ( 'moksafowo-payuni-aftee' === $payment_method ) {
			$base_api_url .= '/trade/common/refund/aftee';
		}
		return $base_api_url;
	}

	public static function get_allowed_payments( $order = null ) {
		if ( ! $order ) {
			return self::$allowed_payments;
		}

		$plugin_version = $order->get_meta( OrderMeta::PLUGIN_VERSION );
		if ( \version_compare( $plugin_version, '1.5.0' ) >= 0 ) {
			return self::$allowed_payments;
		} else {
			$old_allowed_payments = array();
			foreach ( self::$allowed_payments as $key => $value ) {
				$old_payment_id                          = str_replace( 'upp-', '', $key );
				$old_allowed_payments[ $old_payment_id ] = $value;
			}
			return $old_allowed_payments;
		}
	}


	public static function get_allowed_install_payments( $order = null ) {
		if ( ! $order ) {
			return self::$available_installments;
		}

		$plugin_version = $order->get_meta( OrderMeta::PLUGIN_VERSION );
		if ( \version_compare( $plugin_version, '1.5.0' ) >= 0 ) {
			return self::$available_installments;
		} else {
			$old_available_payments = array();
			foreach ( self::$available_installments as $key => $value ) {
				$old_payment_id                            = str_replace( 'upp-', '', $key );
				$old_available_payments[ $old_payment_id ] = $value;
			}
			return $old_available_payments;
		}
	}


	public static function get_order_meta_key( $order, $key ) {
		$plugin_version = $order->get_meta( OrderMeta::PLUGIN_VERSION );
		if ( \version_compare( $plugin_version, '1.5.0' ) >= 0 ) {
			return $key;
		} else {
			return str_replace( '_moksafowo_payuni_', '_payuni_', $key );
		}
	}


	public static function log( $message, $level = 'info' ) {
		if ( ! self::$log_enabled ) {
			return;
		}
		$msg    = is_string( $message ) ? $message : (string) wp_json_encode( $message );
		$method = in_array( $level, [ 'info', 'warning', 'error', 'debug' ], true ) ? $level : 'info';
		\Moksafowo\Logging\Logger::{$method}( 'moksafowo-payuni-payment', $msg );
	}


	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
