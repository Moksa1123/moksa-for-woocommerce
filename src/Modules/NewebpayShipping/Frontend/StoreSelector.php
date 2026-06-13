<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\NewebpayShipping\Frontend;

use MoksaWeb\Mowc\Modules\NewebpayShipping\Api\Helper;
use MoksaWeb\Mowc\Modules\NewebpayShipping\Api\ShippingRequest;
use MoksaWeb\Mowc\Modules\NewebpayShipping\Module;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class StoreSelector {

	private const NONCE_ACTION     = 'moksafowo_newebpay_shipping_store';
	private const TRANSIENT_PREFIX = 'moksafowo_newebpay_store_';
	private const STATE_PREFIX     = 'moksafowo_newebpay_state_';
	private const SESSION_KEY      = 'moksafowo_newebpay_shipping_store';
	private const TOKEN_QUERY      = 'moksafowo_newebpay_store';

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );

		// AJAX：開地圖 / 解 token
		add_action( 'wp_ajax_moksafowo_newebpay_shipping_open_map', [ __CLASS__, 'ajax_open_map' ] );
		add_action( 'wp_ajax_nopriv_moksafowo_newebpay_shipping_open_map', [ __CLASS__, 'ajax_open_map' ] );
		add_action( 'wp_ajax_moksafowo_newebpay_shipping_resolve_token', [ __CLASS__, 'ajax_resolve_token' ] );
		add_action( 'wp_ajax_nopriv_moksafowo_newebpay_shipping_resolve_token', [ __CLASS__, 'ajax_resolve_token' ] );

		// 藍新 storeMap callback
		add_action( 'woocommerce_api_moksafowo_newebpay_shipping_map_callback', [ __CLASS__, 'handle_callback' ] );

		// 下單 → 把 session store 寫進 order meta（Classic + Block）
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_to_order' ], 20, 2 );
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
		if ( ! self::is_checkout_page() ) {
			return;
		}
		$handle  = 'moksafowo-newebpay-shipping-store';
		$js_path = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/NewebpayShipping/assets/js/store-selector.js';
		$ver     = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MOKSAFOWO_VERSION;
		wp_register_script(
			$handle,
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/NewebpayShipping/assets/js/store-selector.js',
			[ 'jquery' ],
			$ver,
			true
		);
		wp_localize_script( $handle, 'moksafowo_newebpay_shipping', [
			'ajax_url'    => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
			'cvs_methods' => array_keys( Module::method_map() ),
			'token_query' => self::TOKEN_QUERY,
			'i18n'        => [
				'select'        => __( '選擇取貨門市', 'mo-ectools' ),
				'change'        => __( '更換門市', 'mo-ectools' ),
				'none_selected' => __( '尚未選擇取貨門市', 'mo-ectools' ),
				'store_id'      => __( '門市代號', 'mo-ectools' ),
				'error'         => __( '無法開啟藍新選店畫面，請稍後再試。', 'mo-ectools' ),
			],
		] );
		wp_enqueue_script( $handle );
	}

	private static function is_checkout_page(): bool {
		if ( is_checkout() ) {
			return true;
		}
		$post = get_post();
		return $post instanceof \WP_Post && (
			has_block( 'woocommerce/checkout', $post )
			|| has_block( 'woocommerce/classic-shortcode', $post )
			|| has_shortcode( $post->post_content, 'woocommerce_checkout' )
		);
	}

	public static function ajax_open_map(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$method_id = isset( $_POST['shipping_method'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_method'] ) ) : '';
		if ( str_contains( $method_id, ':' ) ) {
			$method_id = strstr( $method_id, ':', true );
		}
		if ( ! isset( Module::method_map()[ $method_id ] ) ) {
			wp_send_json_error( [ 'message' => __( '不是藍新物流方式。', 'mo-ectools' ) ] );
		}

		// state token 存 transient anti-tamper
		$mtn = self::generate_mtn();
		set_transient( self::STATE_PREFIX . $mtn, [ 'method_id' => $method_id, 'time' => time() ], 30 * MINUTE_IN_SECONDS );

		$lgs_type  = (string) get_option( 'moksafowo_newebpay_shipping_lgs_type', 'C2C' );
		$ship_type = isset( $_POST['ship_type'] ) ? sanitize_text_field( wp_unslash( $_POST['ship_type'] ) ) : '1';

		// 過濾未啟用的超商品牌（per settings 的「啟用的超商」multiselect）
		$enabled = (array) get_option( 'moksafowo_newebpay_shipping_enabled_carriers', [ '1', '2', '3', '4' ] );
		if ( $enabled && ! in_array( (string) $ship_type, $enabled, true ) ) {
			// 落到第一個有啟用的當 fallback
			$ship_type = (string) reset( $enabled ) ?: '1';
		}
		// referrer to redirect back to original checkout page
		$referrer = isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '';
		set_transient( self::STATE_PREFIX . $mtn . '_ref', $referrer, 30 * MINUTE_IN_SECONDS );

		$result = ShippingRequest::open_store_map( [
			'MerchantOrderNo' => $mtn,
			'LgsType'         => $lgs_type,
			'ShipType'        => $ship_type,
			'ReturnURL'       => add_query_arg( 'wc-api', 'moksafowo_newebpay_shipping_map_callback', home_url( '/' ) ),
		] );
		if ( ! $result['ok'] ) {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
		wp_send_json_success( [
			'api_url'   => $result['api_url'],
			'form_data' => $result['form_data'],
		] );
	}

	public static function handle_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- External store callback POST; hash verified inside this method.
		$encrypted = isset( $_POST['EncryptData'] ) ? (string) wp_unslash( $_POST['EncryptData'] ) : '';
		$hash      = isset( $_POST['HashData'] ) ? (string) wp_unslash( $_POST['HashData'] ) : '';
		if ( '' === $encrypted ) {
			Helper::log( 'storeMap callback: empty EncryptData' );
			status_header( 400 );
			exit( 'Missing payload' );
		}
		// 驗 HashData (optional but safe)
		$expected = strtoupper( hash( 'sha256', 'HashKey=' . Helper::hash_key() . '&' . $encrypted . '&HashIV=' . Helper::hash_iv() ) );
		if ( '' !== $hash && ! hash_equals( $expected, strtoupper( $hash ) ) ) {
			Helper::log( 'storeMap callback: HashData mismatch' );
			status_header( 400 );
			exit( 'Bad hash' );
		}

		$decrypted = Helper::decrypt_trade_info( $encrypted );
		if ( ! is_array( $decrypted ) ) {
			Helper::log( 'storeMap callback: decrypt failed' );
			status_header( 400 );
			exit( 'Decrypt fail' );
		}

		$mtn = (string) ( $decrypted['MerchantOrderNo'] ?? '' );
		// 驗 mtn 是我們開過的
		$state = $mtn !== '' ? get_transient( self::STATE_PREFIX . $mtn ) : null;
		if ( ! is_array( $state ) ) {
			Helper::log( 'storeMap callback: unknown MTN', [ 'mtn' => $mtn ] );
			status_header( 403 );
			exit( 'Unknown trade' );
		}

		// 解密內容源自遠端輸入 — cast 不等於 sanitize，逐欄 sanitize 後才存 session / 寫 meta。
		$clean = static fn( $k ) => sanitize_text_field( (string) ( $decrypted[ $k ] ?? '' ) );
		$store = [
			'id'        => $clean( 'StoreID' ),
			'name'      => $clean( 'StoreName' ),
			'address'   => $clean( 'StoreAddr' ),
			'telephone' => $clean( 'StoreTel' ),
			'lgs_type'  => $clean( 'LgsType' ),
			'ship_type' => $clean( 'ShipType' ),
			'method_id' => sanitize_text_field( (string) ( $state['method_id'] ?? '' ) ),
			'mtn'       => $mtn,
		];
		if ( '' === $store['id'] ) {
			status_header( 400 );
			exit( 'Missing StoreID' );
		}

		// transient + session 雙寫
		$token = wp_generate_password( 24, false );
		set_transient( self::TRANSIENT_PREFIX . $token, $store, 30 * MINUTE_IN_SECONDS );
		if ( function_exists( 'WC' ) ) {
			WC()->initialize_session();
			if ( WC()->session ) {
				WC()->session->set( self::SESSION_KEY, $store );
			}
		}

		// 清掉 state transient
		delete_transient( self::STATE_PREFIX . $mtn );
		$referrer = (string) get_transient( self::STATE_PREFIX . $mtn . '_ref' );
		delete_transient( self::STATE_PREFIX . $mtn . '_ref' );

		// 重導回原結帳頁 + token
		$base = $referrer !== '' ? $referrer : wc_get_checkout_url();
		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$base_host = wp_parse_url( $base, PHP_URL_HOST );
		if ( $base_host !== $home_host ) {
			$base = wc_get_checkout_url();
		}
		$url = add_query_arg( self::TOKEN_QUERY, $token, $base );
		wp_safe_redirect( $url );
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
		if ( function_exists( 'WC' ) ) {
			WC()->initialize_session();
			if ( WC()->session ) {
				WC()->session->set( self::SESSION_KEY, $store );
			}
		}
		delete_transient( self::TRANSIENT_PREFIX . $token );
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
		// 確認訂單真的是 NewebPay 物流
		$is_match = false;
		foreach ( $order->get_shipping_methods() as $m ) {
			if ( str_starts_with( (string) $m->get_method_id(), 'moksafowo_newebpay_shipping_' ) ) {
				$is_match = true;
				break;
			}
		}
		if ( ! $is_match ) {
			return;
		}
		$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_STORE_ID, $store['id'] );
		$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_STORE_NAME, $store['name'] ?? '' );
		$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_STORE_ADDR, $store['address'] ?? '' );
		$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_LGS_TYPE, $store['lgs_type'] ?? 'C2C' );
		$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_SHIP_TYPE, $store['ship_type'] ?? '1' );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_PROVIDER, 'newebpay' );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_ID, $store['id'] );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_NAME, $store['name'] ?? '' );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_ADDRESS, $store['address'] ?? '' );
		WC()->session->set( self::SESSION_KEY, null );
	}

	private static function generate_mtn(): string {
		return substr( 'mowpns' . time() . bin2hex( random_bytes( 3 ) ), 0, 30 );
	}
}
