<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Frontend;

use Moksafowo\Modules\SmilepayShipping\Api\Helper;
use Moksafowo\Modules\SmilepayShipping\Api\ShippingRequest;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class StoreSelector {

	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// callback endpoint — SmilePay 選店後 redirect 回此
		add_action( 'woocommerce_api_moksafowo_smilepay_shipping_emap', [ __CLASS__, 'handle_emap_callback' ] );
		// 後台改門市走另一個 endpoint —— EMAP 的 url 參數不能帶 query string（SmilePay 回傳時會直接接 ?storeid=）
		add_action( 'woocommerce_api_moksafowo_smilepay_shipping_emap_admin', [ __CLASS__, 'handle_admin_emap_callback' ] );

		// 結帳頁 enqueue JS 注入按鈕
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );

		// 下單時 session → order meta
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
		if ( ! is_checkout() && ! self::has_checkout_block() ) {
			return;
		}
		$handle  = 'moksafowo-smilepay-shipping-store';
		$js_path = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/SmilepayShipping/assets/js/store-selector.js';
		$ver     = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MOKSAFOWO_VERSION;

		// 共用超商選店卡片樣式 + moksafowoCvsStore helper(對齊 PAYUNi / 藍新)。
		\Moksafowo\Modules\Shared\Frontend\CvsStoreAssets::enqueue();

		wp_register_script(
			$handle,
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/SmilepayShipping/assets/js/store-selector.js',
			[ 'jquery', \Moksafowo\Modules\Shared\Frontend\CvsStoreAssets::SCRIPT ],
			$ver,
			true
		);

		// 預先計算 EMAP URL（per shipping method）讓 JS 直接打開
		$cvs_service_type = Helper::cvs_service_type(); // C2C or B2C
		$return_url       = home_url( '/wc-api/moksafowo_smilepay_shipping_emap' );
		$tempvar          = wp_create_nonce( 'moksafowo_smilepay_emap_' . get_current_user_id() );

		$emap_urls = [
			'moksafowo_smilepay_shipping_cvs_711'  => ShippingRequest::build_emap_url(
				'711' . $cvs_service_type,
				$tempvar,
				$return_url
			),
			'moksafowo_smilepay_shipping_cvs_fami' => ShippingRequest::build_emap_url(
				'FAMI' . $cvs_service_type,
				$tempvar,
				$return_url
			),
		];

		// 顯示已選的店（從 session 取）
		$selected_store = WC()->session ? [
			'id'      => (string) WC()->session->get( 'moksafowo_smilepay_shipping_store_id', '' ),
			'name'    => (string) WC()->session->get( 'moksafowo_smilepay_shipping_store_name', '' ),
			'address' => (string) WC()->session->get( 'moksafowo_smilepay_shipping_store_address', '' ),
		] : [];

		wp_localize_script(
			$handle,
			'moksafowo_smilepay_shipping',
			[
				'emap_urls'      => $emap_urls,
				'cvs_methods'    => array_keys( $emap_urls ),
				'selected_store' => $selected_store,
				'i18n'           => [
					'select'        => __( 'Choose a pickup store', 'moksa-for-woocommerce' ),
					'change'        => __( 'Change store', 'moksa-for-woocommerce' ),
					'none_selected' => __( 'No pickup store chosen yet', 'moksa-for-woocommerce' ),
					'store_id'      => __( 'Store number', 'moksa-for-woocommerce' ),
				],
			]
		);
		wp_enqueue_script( $handle );
	}

	private static function has_checkout_block(): bool {
		$post = get_post();
		return $post instanceof \WP_Post && (
			has_block( 'woocommerce/checkout', $post )
			|| has_shortcode( $post->post_content, 'woocommerce_checkout' )
		);
	}

	private const ADMIN_STATE = 'moksafowo_smilepay_admin_emap_';

	/**
	 * 後台改門市用的 EMAP 網址。SmilePay 是 GET 導頁，沒有簽章可驗，
	 * 所以回程只認「同一個管理者剛剛開過地圖」的 transient，而且只畫頁面不寫訂單。
	 *
	 * @return array{url:string}|\WP_Error
	 */
	public static function admin_map_payload( \WC_Order $order, string $method_id ) {
		$brands = [
			'moksafowo_smilepay_shipping_cvs_711'  => '711',
			'moksafowo_smilepay_shipping_cvs_fami' => 'FAMI',
		];
		if ( ! isset( $brands[ $method_id ] ) ) {
			return new \WP_Error( 'moksafowo_not_cvs', __( 'This shipping method does not need a store.', 'moksa-for-woocommerce' ) );
		}

		set_transient( self::ADMIN_STATE . get_current_user_id(), 1, 30 * MINUTE_IN_SECONDS );

		return [
			'url' => ShippingRequest::build_emap_url(
				$brands[ $method_id ] . Helper::cvs_service_type(),
				wp_create_nonce( 'moksafowo_smilepay_emap_' . get_current_user_id() ),
				home_url( '/wc-api/moksafowo_smilepay_shipping_emap_admin' )
			),
		];
	}

	public static function handle_admin_emap_callback(): void {
		// SmilePay EMAP 回程：GET 導頁、協定無簽章。這裡不寫任何訂單／session 狀態，
		// 只把門市畫回開啟它的訂單編輯頁；門市要等商家在後台按更新訂單才會存。
		if ( ! current_user_can( 'edit_shop_orders' ) || ! get_transient( self::ADMIN_STATE . get_current_user_id() ) ) {
			wp_die( esc_html__( 'You are not allowed to edit orders.', 'moksa-for-woocommerce' ), 403 );
		}
		delete_transient( self::ADMIN_STATE . get_current_user_id() );

		$store = [
			'id'      => isset( $_GET['storeid'] ) ? sanitize_text_field( wp_unslash( $_GET['storeid'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- SmilePay EMAP redirect; no nonce possible (protocol limitation); guarded by capability + one-shot transient above; render-only, no state written.
			'name'    => isset( $_GET['storename'] ) ? sanitize_text_field( wp_unslash( $_GET['storename'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
			'address' => isset( $_GET['storeaddress'] ) ? sanitize_text_field( wp_unslash( $_GET['storeaddress'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		];
		if ( '' === $store['id'] ) {
			wp_die( esc_html__( 'No pickup store has been chosen yet.', 'moksa-for-woocommerce' ), 400 );
		}

		\Moksafowo\Modules\Shipping\Admin\CvsStoreEditor::render_return( $store );
	}

	public static function handle_emap_callback(): void {
		// SmilePay EMAP store callback: no WP nonce possible (SmilePay redirects the browser back here).
		// SmilePay EMAP protocol does not include a callback hash/signature.
		// Guard: session-only write (no order state mutation here); storeid is validated to be non-empty
		// before writing; all fields sanitized at capture via sanitize_text_field + wp_unslash.
		// Order meta is written only at checkout via save_to_order(), which fires after WC checkout nonce check.
		$store_id      = isset( $_REQUEST['storeid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['storeid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- SmilePay EMAP callback; no nonce/hash possible (protocol limitation); session-only write, sanitized; order meta written later under WC checkout nonce.
		$store_name    = isset( $_REQUEST['storename'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['storename'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- SmilePay EMAP callback; session-only write, sanitized.
		$store_address = isset( $_REQUEST['storeaddress'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['storeaddress'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- SmilePay EMAP callback; session-only write, sanitized.

		if ( '' === $store_id ) {
			Helper::log( 'EMAP callback missing storeid' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// 寫進 session
		if ( ! WC()->session ) {
			WC()->initialize_session();
		}
		WC()->session->set( 'moksafowo_smilepay_shipping_store_id', $store_id );
		WC()->session->set( 'moksafowo_smilepay_shipping_store_name', $store_name );
		WC()->session->set( 'moksafowo_smilepay_shipping_store_address', $store_address );

		Helper::log(
			'EMAP callback ok',
			[
				'store_id'   => $store_id,
				'store_name' => $store_name,
			]
		);

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/** 結帳驗證用：顧客這次 session 選了哪個門市（沒有就空字串）。 */
	public static function session_store_id(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}
		return (string) WC()->session->get( 'moksafowo_smilepay_shipping_store_id', '' );
	}

	public static function save_to_order( $order, $data ): void {
		if ( ! $order instanceof \WC_Order || ! WC()->session ) {
			return;
		}
		// 只處理 SmilePay shipping 訂單
		$is_smilepay_cvs = false;
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid = (string) $m->get_method_id();
			if ( str_contains( $mid, 'moksafowo_smilepay_shipping_cvs_' ) ) {
				$is_smilepay_cvs = true;
				break;
			}
		}
		if ( ! $is_smilepay_cvs ) {
			return;
		}
		$store_id   = (string) WC()->session->get( 'moksafowo_smilepay_shipping_store_id', '' );
		$store_name = (string) WC()->session->get( 'moksafowo_smilepay_shipping_store_name', '' );
		$store_addr = (string) WC()->session->get( 'moksafowo_smilepay_shipping_store_address', '' );
		if ( '' === $store_id ) {
			return;
		}
		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_STORE_ID, $store_id );
		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_STORE_NAME, $store_name );
		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_STORE_ADDR, $store_addr );

		// 清掉 session（避免下一張訂單沿用）
		WC()->session->__unset( 'moksafowo_smilepay_shipping_store_id' );
		WC()->session->__unset( 'moksafowo_smilepay_shipping_store_name' );
		WC()->session->__unset( 'moksafowo_smilepay_shipping_store_address' );
	}
}
