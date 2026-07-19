<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Frontend;

use Moksafowo\Modules\EcpayShipping\Api\Helper;
use Moksafowo\Modules\EcpayShipping\Module;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class StoreSelector {

	private const NONCE_ACTION        = 'moksafowo_ecpay_shipping_store';
	private const TRANSIENT_PREFIX    = 'moksafowo_ecpay_store_';        // store data after callback (token).
	private const STATE_PREFIX        = 'moksafowo_ecpay_state_';         // session-bound state for anti-tamper.
	private const SESSION_KEY         = 'moksafowo_ecpay_shipping_store';
	private const SESSION_PENDING_KEY = 'moksafowo_ecpay_shipping_pending_mtn';
	private const TOKEN_QUERY         = 'moksafowo_ecpay_store';

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'woocommerce_review_order_after_shipping', [ __CLASS__, 'render_classic' ] );
		add_action( 'wp_ajax_moksafowo_ecpay_shipping_open_map', [ __CLASS__, 'ajax_open_map' ] );
		add_action( 'wp_ajax_nopriv_moksafowo_ecpay_shipping_open_map', [ __CLASS__, 'ajax_open_map' ] );

		add_action( 'wp_ajax_moksafowo_ecpay_shipping_get_store', [ __CLASS__, 'ajax_get_store' ] );
		add_action( 'wp_ajax_nopriv_moksafowo_ecpay_shipping_get_store', [ __CLASS__, 'ajax_get_store' ] );

		add_action( 'wp_ajax_moksafowo_ecpay_shipping_resolve_token', [ __CLASS__, 'ajax_resolve_token' ] );
		add_action( 'wp_ajax_nopriv_moksafowo_ecpay_shipping_resolve_token', [ __CLASS__, 'ajax_resolve_token' ] );

		add_action( 'woocommerce_api_moksafowo_ecpay_shipping_map_callback', [ __CLASS__, 'handle_callback' ] );
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_to_order' ], 20, 2 );
		// Block Store API 不 fire create_order；跳過試算 call（__experimental_calc_totals）
		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			static function ( $order, $request ) {
				if ( $request && method_exists( $request, 'get_param' ) && $request->get_param( '__experimental_calc_totals' ) ) {
					return;
				}
				self::save_to_order( $order, [] );
			},
			20,
			2
		);
	}

	public static function enqueue(): void {
		if ( ! is_checkout() ) {
			return;
		}

		$handle   = 'moksafowo-ecpay-shipping-store';
		$js_path  = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/EcpayShipping/assets/js/store-selector.js';
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MOKSAFOWO_VERSION;
		$css_path = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/EcpayShipping/assets/css/store-selector.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : MOKSAFOWO_VERSION;

		\Moksafowo\Modules\Shared\Frontend\CvsStoreAssets::enqueue();

		wp_register_script(
			$handle,
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/EcpayShipping/assets/js/store-selector.js',
			[ 'jquery', 'wp-i18n', \Moksafowo\Modules\Shared\Frontend\CvsStoreAssets::SCRIPT ],
			$js_ver,
			true
		);
		wp_localize_script(
			$handle,
			'moksafowo_ecpay_shipping',
			[
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
				'cvs_methods' => array_keys(
					array_filter(
						Module::method_map(),
						static fn( string $cls ) => is_subclass_of( $cls, \Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod::class )
					)
				),
				'token_query' => self::TOKEN_QUERY,
				'i18n'        => [
					'select'        => __( '選擇取貨門市', 'moksa-for-woocommerce' ),
					'change'        => __( '更換門市', 'moksa-for-woocommerce' ),
					'none_selected' => __( '尚未選擇門市', 'moksa-for-woocommerce' ),
					'store_id'      => __( '門市代號', 'moksa-for-woocommerce' ),
					'error'         => __( '無法開啟綠界選店畫面，請稍後再試。', 'moksa-for-woocommerce' ),
				],
			]
		);
		wp_enqueue_script( $handle );

		wp_register_style(
			$handle,
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/EcpayShipping/assets/css/store-selector.css',
			[],
			$css_ver
		);
		wp_enqueue_style( $handle );
	}

	public static function render_classic(): void {
		echo '<tr class="moksafowo-ecpay-shipping-store-row" style="display:none"><th></th><td><div id="moksafowo-ecpay-shipping-store-host" class="moksafowo-ecpay-shipping-store"></div></td></tr>';
	}

	public static function ajax_open_map(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$method_id = isset( $_POST['shipping_method'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_method'] ) ) : '';
		if ( str_contains( $method_id, ':' ) ) { // 剝掉 :instance_id
			$method_id = strstr( $method_id, ':', true );
		}

		$map = Module::method_map();
		if ( ! isset( $map[ $method_id ] ) ) {
			wp_send_json_error( [ 'message' => __( '不是綠界物流方式。', 'moksa-for-woocommerce' ) ] );
		}

		$class = $map[ $method_id ];
		if ( ! is_subclass_of( $class, \Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod::class ) ) {
			wp_send_json_error( [ 'message' => __( '此物流方式不需選店。', 'moksa-for-woocommerce' ) ] );
		}

		$method = new $class();

		$pending_id = self::pending_id();
		$mtn        = Helper::generate_merchant_trade_no( $pending_id );

		$referrer = isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '';
		set_transient(
			self::STATE_PREFIX . $mtn,
			[
				'method_id'  => $method_id,
				'pending_id' => $pending_id,
				'referrer'   => $referrer,
			],
			30 * MINUTE_IN_SECONDS
		);

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_PENDING_KEY, $mtn );
		}

		$is_collection = self::is_cod_enabled();
		// MerchantID 必須對應 subtype 的憑證組：B2C 方式配 C2C 商號會讓 ECPay 退「找不到加密金鑰」
		$subtype                  = $method->logistics_sub_type();
		$payload                  = [
			'MerchantID'       => Helper::merchant_id( $subtype ),
			'MerchantTradeNo'  => $mtn,
			'LogisticsType'    => 'CVS',
			'LogisticsSubType' => $subtype,
			'IsCollection'     => $is_collection ? 'Y' : 'N',
			'ServerReplyURL'   => add_query_arg( 'wc-api', 'moksafowo_ecpay_shipping_map_callback', home_url( '/' ) ),
			'ExtraData'        => '',
			'Device'           => wp_is_mobile() ? '1' : '0',
		];
		$payload['CheckMacValue'] = Helper::generate_check_mac_value( $payload, $subtype );

		wp_send_json_success(
			[
				'api_url'   => Helper::map_endpoint(),
				'form_data' => $payload,
			]
		);
	}

	public static function handle_callback(): void {
		// ECPay store-map callback: cross-site POST from ECPay server; no WP nonce possible.
		// Anti-tamper guard: MerchantTradeNo must match a transient set when the map was legitimately opened
		// (STATE_PREFIX . $mtn transient, line ~180). Unknown MTN → 403 before any session write.
		// All fields sanitized via wc_clean + wp_unslash at capture; only session/transient written (no order state mutation).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ECPay store-map callback; no WP nonce possible; source verified via MTN transient anti-tamper before any use; session-only write, no state mutation; all fields sanitized via wc_clean.
		$posted = isset( $_POST ) ? wc_clean( wp_unslash( $_POST ) ) : array();

		Helper::log( 'shipping map callback raw', [ 'data' => $posted ] );

		$mtn = isset( $posted['MerchantTradeNo'] ) ? wc_clean( wp_unslash( $posted['MerchantTradeNo'] ) ) : '';
		if ( '' === $mtn ) {
			status_header( 400 );
			echo 'Missing MerchantTradeNo';
			exit;
		}

		$state = get_transient( self::STATE_PREFIX . $mtn ); // anti-tamper：confirm 我們有開過此 MTN
		if ( ! is_array( $state ) ) {
			Helper::log( 'shipping map callback unknown MTN — rejected', [ 'mtn' => $mtn ] );
			status_header( 403 );
			echo 'Unknown trade';
			exit;
		}

		$store = [
			'id'        => isset( $posted['CVSStoreID'] ) ? wc_clean( wp_unslash( $posted['CVSStoreID'] ) ) : '',
			'name'      => isset( $posted['CVSStoreName'] ) ? wc_clean( wp_unslash( $posted['CVSStoreName'] ) ) : '',
			'address'   => isset( $posted['CVSAddress'] ) ? wc_clean( wp_unslash( $posted['CVSAddress'] ) ) : '',
			'telephone' => isset( $posted['CVSTelephone'] ) ? wc_clean( wp_unslash( $posted['CVSTelephone'] ) ) : '',
			'outside'   => isset( $posted['CVSOutSide'] ) ? wc_clean( wp_unslash( $posted['CVSOutSide'] ) ) : '0',
			'method_id' => $state['method_id'],
			'mtn'       => $mtn,
		];

		if ( '' === $store['id'] ) {
			status_header( 400 );
			echo 'Missing CVSStoreID';
			exit;
		}

		$token = wp_generate_password( 24, false );
		set_transient( self::TRANSIENT_PREFIX . $token, $store, 30 * MINUTE_IN_SECONDS );

		if ( function_exists( 'WC' ) ) {
			WC()->initialize_session();
			if ( WC()->session ) {
				WC()->session->set( self::SESSION_KEY, $store );
			}
		}

		delete_transient( self::STATE_PREFIX . $mtn ); // one-shot
		$base_url = ! empty( $state['referrer'] ) ? (string) $state['referrer'] : wc_get_checkout_url();
		// referrer 必須是同站 URL
		$home     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$ref_host = wp_parse_url( $base_url, PHP_URL_HOST );
		if ( $ref_host !== $home ) {
			$base_url = wc_get_checkout_url();
		}
		$checkout_url = add_query_arg( self::TOKEN_QUERY, $token, $base_url );
		wp_safe_redirect( $checkout_url );
		exit;
	}

	public static function ajax_resolve_token(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( '' === $token ) {
			wp_send_json_error( [ 'message' => 'missing token' ] );
		}

		$store = get_transient( self::TRANSIENT_PREFIX . $token );
		if ( ! is_array( $store ) ) {
			wp_send_json_error( [ 'message' => 'expired' ] );
		}

		// 寫進當前 session（callback session 可能不同於瀏覽器 session）
		if ( function_exists( 'WC' ) ) {
			WC()->initialize_session();
			if ( WC()->session ) {
				WC()->session->set( self::SESSION_KEY, $store );
			}
		}
		delete_transient( self::TRANSIENT_PREFIX . $token );
		wp_send_json_success( $store );
	}

	public static function ajax_get_store(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			wp_send_json_error( [ 'message' => 'no session' ] );
		}
		$store = WC()->session->get( self::SESSION_KEY );
		if ( ! is_array( $store ) || empty( $store['id'] ) ) {
			wp_send_json_error( [ 'message' => 'no store' ] );
		}
		wp_send_json_success( $store );
	}

	public static function save_to_order( \WC_Order $order, array $data ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$store = WC()->session->get( self::SESSION_KEY );
		if ( ! is_array( $store ) || empty( $store['id'] ) ) {
			return;
		}

		$is_ecpay_cvs = false;
		foreach ( $order->get_shipping_methods() as $method ) {
			$mid = $method->get_method_id();
			if ( isset( Module::method_map()[ $mid ] ) ) {
				$cls = Module::method_map()[ $mid ];
				if ( is_subclass_of( $cls, \Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod::class ) ) {
					$is_ecpay_cvs = true;
					break;
				}
			}
		}
		if ( ! $is_ecpay_cvs ) {
			return;
		}

		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_PROVIDER, 'ecpay' );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_ID, $store['id'] );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_NAME, $store['name'] ?? '' );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_ADDRESS, $store['address'] ?? '' );

		// MerchantTradeNo 存進 order — IPN fallback meta_key lookup 用
		if ( ! empty( $store['mtn'] ) ) {
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_MERCHANT_TRADE_NO, $store['mtn'] );
		}

		WC()->session->set( self::SESSION_KEY, null );
	}

	private static function pending_id(): int {
		return 0; // map 階段尚無 order_id；random_bytes 部分仍唯一
	}

	private static function is_cod_enabled(): bool {
		return false; // 目前固定 N；未來視需求接設定
	}
}
