<?php
namespace MoksaWeb\Mowc\Modules\PayuniShipping\Frontend;

use Automattic\Jetpack\Constants;
use MoksaWeb\Mowc\Modules\Payuni\Credentials;
use MoksaWeb\Mowc\Modules\PayuniShipping\Api\ShippingRequest;
use MoksaWeb\Mowc\Modules\PayuniShipping\PayuniShipping;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\OrderMeta;
use MoksaWeb\Mowc\Order\Meta\Keys;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\LgsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\GoodsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ServiceType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShipType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

class StoreSelector {

	use SingletonTrait;

	public static function init() {

		self::get_instance();

		// 在運送方式後方顯示超商選擇區塊
		add_action( 'woocommerce_review_order_after_shipping', array( self::get_instance(), 'display_store_selector_after_shipping' ) );

		// Guest-checkout flow needs nopriv. Each handler guards itself with the
		// `payuni_store_search` nonce + WC session binding (see ajax_open_store_map).
		add_action( 'wp_ajax_payuni_open_store_map', array( __CLASS__, 'ajax_open_store_map' ) );
		add_action( 'wp_ajax_nopriv_payuni_open_store_map', array( __CLASS__, 'ajax_open_store_map' ) );

		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_store_selection' ), 10, 2 );

		// Block Store API 不 fire `woocommerce_checkout_create_order` — 必須補一刀
		// 才能把 session 的選店資料寫進訂單 meta（否則 Block 結帳的訂單永遠沒 store）。
		// 但 update_order_from_request 也會在 ?__experimental_calc_totals=true 的
		// 重新計算金額 call 上 fire（換金流時 Block 會打一次） — 那種 call 不是真的
		// 下單，session 不能在那種時機被清掉，否則接下來真的下單就 throw NoStore。
		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			static function ( $order, $request ) {
				if ( $request && $request->get_param( '__experimental_calc_totals' ) ) {
					return;
				}
				self::save_store_selection( $order, array() );
			},
			20,
			2
		);

		add_action( 'wp_ajax_payuni_get_store_data', array( __CLASS__, 'ajax_get_store_data' ) );
		add_action( 'wp_ajax_nopriv_payuni_get_store_data', array( __CLASS__, 'ajax_get_store_data' ) );

		// Token-based fallback for the cross-site cookie problem: PAYUNi
		// calls wc-api/payuni_store_callback from a cross-site POST that
		// drops the wp_woocommerce_session_<hash> cookie under SameSite=Lax,
		// so handle_store_map_return ends up writing to a brand new session
		// the visitor never sees again. We persist the result in a 30-min
		// transient keyed by a random token, and Block JS resolves it on
		// the way back via this endpoint.
		add_action( 'wp_ajax_payuni_resolve_store_token', array( __CLASS__, 'ajax_resolve_store_token' ) );
		add_action( 'wp_ajax_nopriv_payuni_resolve_store_token', array( __CLASS__, 'ajax_resolve_store_token' ) );

		add_action( 'wp_ajax_payuni_clear_store_data', array( __CLASS__, 'ajax_clear_store_data' ) );
		add_action( 'wp_ajax_nopriv_payuni_clear_store_data', array( __CLASS__, 'ajax_clear_store_data' ) );

		add_action( 'woocommerce_api_payuni_store_callback', array( __CLASS__, 'handle_store_map_return' ) );
		add_action( 'woocommerce_api_payuni_admin_store_callback', array( __CLASS__, 'handle_admin_store_map_return' ) );

		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'add_store_data_fragment' ) );

		// 在 AJAX 更新時同步運送方式資訊
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'update_session_shipping_method' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		// Skip billing address validation for CVS shipping when setting is enabled
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'modify_billing_fields_for_cvs' ), 20 );

		// Override default billing address validation
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'set_default_billing_address_for_cvs' ), 10 );

		// FIX: scrub leftover "N/A" placeholder values when the visitor switches
		// from a CVS method to home-delivery (TCat / etc). wpbr defaulted billing
		// fields to "N/A" which got cached in the form and reappeared on later
		// renders even after the user picked a real address-required method.
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'strip_na_placeholder_for_home_delivery' ), 10, 2 );

		// Block 結帳走 Store API 不過 woocommerce_checkout_posted_data filter，
		// 要在 woocommerce_checkout_create_order 上補一刀，確保 Block 流程也
		// 清空 CVS 訂單的 shipping/billing 地址。Classic + Block 都會 fire 這個
		// hook，pri 100 確保在 WC 自己處理完後才動。
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'clear_addresses_for_cvs_order' ), 100, 2 );
	}

	public static function clear_addresses_for_cvs_order( \WC_Order $order, array $data ): void {
		if ( get_option( 'mo_payuni_shipping_hide_billing_address_fields', 'no' ) !== 'yes' ) {
			return;
		}
		// 從 $order 上的 shipping methods 反推是不是 CVS 訂單
		$is_cvs = false;
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( PayuniShipping::needs_cvs( $method->get_method_id() ) ) {
				$is_cvs = true;
				break;
			}
		}
		if ( ! $is_cvs ) {
			return;
		}

		$order->set_billing_address_1( '' );
		$order->set_billing_address_2( '' );
		$order->set_billing_city( '' );
		$order->set_billing_state( '' );
		$order->set_billing_postcode( '' );
		$order->set_billing_company( '' );

		$order->set_shipping_address_1( '' );
		$order->set_shipping_address_2( '' );
		$order->set_shipping_city( '' );
		$order->set_shipping_state( '' );
		$order->set_shipping_postcode( '' );
		$order->set_shipping_company( '' );

		PayuniShipping::log( 'CVS order ' . $order->get_id() . ' addresses force-cleared at create_order hook (covers Block + Classic).' );
	}

	public static function strip_na_placeholder_for_home_delivery( $value, $input ) {
		if ( ! WC()->session ) {
			return $value;
		}

		$is_address_field = (bool) preg_match( '/^billing_(address_|postcode|city|state|country|company)/', (string) $input );
		if ( ! $is_address_field ) {
			// Shipping fields & non-address billing fields untouched.
			if ( 'N/A' === $value && (bool) preg_match( '/^shipping_(address_|postcode|city|state|country|company)/', (string) $input ) ) {
				return '';
			}
			return $value;
		}

		$chosen          = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		$shipping_method = $chosen[0] ?? '';
		$method_id       = strpos( $shipping_method, ':' ) !== false ? explode( ':', $shipping_method )[0] : $shipping_method;
		$is_cvs          = $method_id !== '' && PayuniShipping::needs_cvs( $method_id );
		$hide_billing    = get_option( 'mo_payuni_shipping_hide_billing_address_fields', 'no' ) === 'yes';

		// CVS + 隱藏設定開啟：拒 autofill（returning customer 的 saved profile 不灌進隱藏欄位）
		if ( $is_cvs && $hide_billing ) {
			return '';
		}

		// 從 CVS 切到 home delivery 後，殘留 'N/A' 不應該 pre-fill
		if ( ! $is_cvs && 'N/A' === $value ) {
			return '';
		}

		return $value;
	}

	public static function enqueue_scripts() {
		// Don't rely on is_checkout() — that only returns true for the page
		// configured as the WC checkout page. Custom checkout pages (Classic
		// shortcode, Block, classic-shortcode block) need the assets too.
		$post           = get_post();
		$has_checkout   = false;
		if ( $post instanceof \WP_Post ) {
			$has_checkout = has_block( 'woocommerce/checkout', $post )
				|| has_block( 'woocommerce/classic-shortcode', $post )
				|| has_shortcode( $post->post_content, 'woocommerce_checkout' );
		}
		if ( ! is_checkout() && ! $has_checkout ) {
			return;
		}

		// Check if cart needs shipping before loading scripts
		if ( ! WC()->cart || ! WC()->cart->needs_shipping() ) {
			return;
		}

		wp_enqueue_script( 'mo-payuni-store-selector', ( MOWC_PLUGIN_URL . 'src/Modules/PayuniShipping/' ) . 'assets/js/store-selector.js', array( 'jquery' ), MOWC_VERSION, true );
		wp_enqueue_style( 'mo-payuni-store-selector', ( MOWC_PLUGIN_URL . 'src/Modules/PayuniShipping/' ) . 'assets/css/store-selector.css', array(), MOWC_VERSION );

		// Block checkout integration — runs only when the page actually hosts
		// the WC Checkout Block (Classic checkout already works via the
		// `woocommerce_review_order_after_shipping` hook).
		if ( $post && has_block( 'woocommerce/checkout', $post ) ) {
			// filemtime 當版號 — JS 改動每次自動 cache-bust，不靠 plugin VERSION
			$path    = MOWC_PLUGIN_DIR . 'src/Modules/PayuniShipping/assets/js/block-checkout-store.js';
			$version = file_exists( $path ) ? (string) filemtime( $path ) : MOWC_VERSION;
			wp_enqueue_script(
				'mo-payuni-block-checkout-store',
				( MOWC_PLUGIN_URL . 'src/Modules/PayuniShipping/' ) . 'assets/js/block-checkout-store.js',
				array(),
				$version,
				true
			);
			wp_localize_script(
				'mo-payuni-block-checkout-store',
				'mo_payuni_block',
				array(
					'ajax_url'          => admin_url( 'admin-ajax.php' ),
					'search_nonce'      => wp_create_nonce( 'payuni_store_search' ),
					'cvs_method_prefix' => 'mo_payuni_shipping_711',
					'i18n'              => array(
						'select'         => __( '選擇門市', 'mo-ectools' ),
						'change'         => __( '更換門市', 'mo-ectools' ),
						'none'           => __( '尚未選擇取貨門市', 'mo-ectools' ),
						'openMap'        => __( '開啟超商地圖', 'mo-ectools' ),
						'loading'        => __( '載入中…', 'mo-ectools' ),
						'error'          => __( '載入失敗，請稍後再試', 'mo-ectools' ),
						'label'          => __( '已選門市', 'mo-ectools' ),
						'store_id_label' => __( '門市代號:', 'mo-ectools' ),
					),
				)
			);
		}

		// Classic checkout needs to recalc shipping when payment method
		// changes (CVS pickup options can be COD-only). Block checkout
		// handles this itself.
		wp_add_inline_script(
			'mo-payuni-store-selector',
			<<<'JS'
jQuery(function($){$('form.checkout').on('change','input[name="payment_method"]',function(){$(document.body).trigger('update_checkout');});});
JS
		);

		// Enqueue the save-fields script for form preservation
		wp_enqueue_script( 'mo-payuni-save-fields', ( MOWC_PLUGIN_URL . 'src/Modules/PayuniShipping/' ) . 'assets/js/save-fields.js', array( 'jquery' ), MOWC_VERSION, true );

		// Get stored store data from session
		$stored_store_data = null;
		if ( WC()->session ) {
			$stored_store_data = WC()->session->get( 'payuni_selected_store_data', null );	
		} 

		wp_localize_script(
			'mo-payuni-store-selector',
			'mo_payuni_store_selector',
			array(
				'ajax_url'                        => admin_url( 'admin-ajax.php' ),
				'nonce'                           => wp_create_nonce( 'payuni_store_search' ),
				'return_url'                      => home_url( '/?payuni_store_return=1' ),
				'stored_store_data'               => $stored_store_data,
				'hide_billing_address_fields'     => get_option( 'mo_payuni_shipping_hide_billing_address_fields', 'no' ) === 'yes',
				'labels'                          => array(
					'select_store'        => __( '選擇門市', 'mo-ectools' ),
					'change_store'        => __( '更換門市', 'mo-ectools' ),
					'no_store_selected'   => __( '尚未選擇門市', 'mo-ectools' ),
					'open_map'            => __( '選擇門市', 'mo-ectools' ),
					'loading'             => __( '跳轉中...', 'mo-ectools' ),
					'error'               => __( '載入失敗，請稍後再試', 'mo-ectools' ),
				),
			)
		);
	}

	public static function ajax_open_store_map() {
		check_ajax_referer( 'payuni_store_search', 'nonce' );

		$shipping_method = isset( $_POST['shipping_method'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_method'] ) ) : '';

		// Extract method ID without instance (remove :instance_id part)
		$method_id = strpos( $shipping_method, ':' ) !== false ? explode( ':', $shipping_method )[0] : $shipping_method;

		// Bind the signed payload to the visitor's chosen shipping method,
		// so this endpoint can't be used as a signing oracle for arbitrary
		// method ids by anyone who has a valid nonce.
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'message' => __( '購物車為空或會話過期。', 'mo-ectools' ) ), 400 );
		}
		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		if ( ! in_array( $shipping_method, $chosen, true ) ) {
			PayuniShipping::log( 'StoreMap signing-oracle blocked: requested ' . $shipping_method . ' but chosen=' . wp_json_encode( $chosen ) );
			wp_send_json_error( array( 'message' => __( '選取的運送方式與購物車不符。', 'mo-ectools' ) ), 403 );
		}

		// Whitelist must be CVS provider only.
		if ( empty( $method_id ) || strpos( $method_id, 'mo_payuni_shipping_711' ) === false ) {
			wp_send_json_error( array( 'message' => __( '請選擇超商取貨運送方式', 'mo-ectools' ) ) );
		}

		// Issue a one-shot token. Persist a placeholder so the callback can
		// distinguish "valid open_store_map issued this token" from "random
		// `?mo_token=` injection". 30-minute TTL is plenty for the user to
		// pick a store at PAYUNi.
		$token = wp_generate_password( 24, false, false );
		set_transient( 'mowp_payuni_store_' . $token, array( 'pending' => true ), 30 * MINUTE_IN_SECONDS );
		$callback_url = add_query_arg( 'mo_token', $token, WC()->api_request_url( 'payuni_store_callback' ) );

		// 準備 API 參數
		$encrypt_info = array(
			'MerID'        => Credentials::merchant_id(),
			'Timestamp'    => time(),
			'MerKeyNo'     => '1234567890',
			'GoodsType'    => '1', // 1常溫, 2冷凍
			'LgsType'      => LgsType::get_lgs_type_by_shipping_method( $method_id ),
			'ShipType'     => '1', // 1=Seven
			'MapType'      => '2',
			'MapReturnURL' => esc_url_raw( $callback_url ),
			'Tag'          => '2',
			'MobileTag'    => wp_is_mobile() ? 'Y' : 'N',
		);

		PayuniShipping::log( 'PAYUNi Store Map Parameters: ' . wc_print_r( $encrypt_info, true ) );

		$encrypted_info = PayuniShipping::encrypt( $encrypt_info );
		$hash_info      = PayuniShipping::hash_info( $encrypted_info );

		// 準備表單資料
		$form_data = array(
			'MerID'       => Credentials::merchant_id(),
			'Version'     => '1.1',
			'EncryptInfo' => $encrypted_info,
			'HashInfo'    => $hash_info,
		);

		wp_send_json_success( array( 
			'form_data' => $form_data,
			'api_url'   => PayuniShipping::$api_url . '/logistics/ship_map',
		) );
	}

	public static function handle_store_map_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- External store callback POST; hash verified inside this method.
		// 獲取 POST 資料
		$posted = wc_clean( wp_unslash( $_POST ) );
		PayuniShipping::log( 'PAYUNi Store Map Return Data: ' . wc_print_r( $posted, true ) );

		$store_data = array();

		if ( ! empty( $posted ) && isset( $posted['Status'] ) && $posted['Status'] === 'SUCCESS' ) {
			$encrypt_info = $posted['EncryptInfo'] ?? '';
			$decrypted_info = PayuniShipping::decrypt( $encrypt_info );
			
			if ( isset( $decrypted_info['MapJson'] ) ) {
				$map_data = json_decode( $decrypted_info['MapJson'], true );
				
				// 使用統一的 JSON 格式（v2.0）
				$store_data = array(
					'id'        => $map_data['StoreID'] ?? '',
					'name'      => $map_data['StoreName'] ?? '',
					'address'   => $map_data['Address'] ?? '',
				);
			}
		}

		if ( empty( $store_data['id'] ) ) {
			PayuniShipping::log( 'PAYUNi Store Map Return: Missing store data' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// PRIMARY persistence: transient keyed by the one-shot token issued in
		// ajax_open_store_map. PAYUNi's cross-site POST drops SameSite=Lax
		// cookies inconsistently → handle_store_map_return often runs in a
		// brand new (empty) session that the visitor never sees again. The
		// transient is keyed by URL token instead, so JS on the way back can
		// fetch by token regardless of which session ID applies.
		$incoming_token = isset( $_GET['mo_token'] ) ? sanitize_key( wp_unslash( $_GET['mo_token'] ) ) : '';
		if ( strlen( $incoming_token ) >= 16 && get_transient( 'mowp_payuni_store_' . $incoming_token ) !== false ) {
			set_transient( 'mowp_payuni_store_' . $incoming_token, $store_data, 30 * MINUTE_IN_SECONDS );
			PayuniShipping::log( 'Store data saved to transient; token=' . substr( $incoming_token, 0, 8 ) . '…; data=' . wc_print_r( $store_data, true ) );
		} else {
			PayuniShipping::log( 'Store callback missing/invalid mo_token (' . $incoming_token . ') — falling back to session only' );
		}

		// SECONDARY persistence: still write to session as best-effort. When
		// the visitor's session DOES survive the cross-site round-trip
		// (some browsers do preserve), Classic checkout's $_POST + session
		// fallback path keeps working without a JS resolve step.
		$cid_before = WC()->session ? WC()->session->get_customer_id() : '(no session)';
		if ( WC()->session ) {
			WC()->session->set( 'payuni_selected_store_data', $store_data );
		}
		PayuniShipping::log( 'Store data saved to session (best-effort); customer_id=' . $cid_before );

		// 跳轉回結帳頁面並傳遞資料。Append the same one-shot token to the
		// checkout URL so Block JS can resolve the store via the transient
		// regardless of which session the visitor's browser is now using.
		$checkout_url = wc_get_checkout_url();
		if ( $incoming_token !== '' ) {
			$checkout_url = add_query_arg( 'mo_store', $incoming_token, $checkout_url );
		}
		
		// 建立自動提交表單（使用 POST 方式，類似 TCat）
		$html  = '<!doctype html><html ' . get_language_attributes( 'html' ) . '>';
		$html .= '<head><meta charset="' . get_bloginfo( 'charset', 'display' ) . '">';
		$html .= '<title>' . __( '正在返回結帳頁面...', 'mo-ectools' ) . '</title>';
		$html .= '<style>body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f0f0f0; }</style>';
		$html .= '</head>';
		$html .= '<body>';
		$html .= '<div style="max-width: 400px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
		$html .= '<h3 style="color: #333; margin-bottom: 20px;">' . __( '門市選擇完成', 'mo-ectools' ) . '</h3>';
		$html .= '<p style="color: #666; margin-bottom: 10px;">選擇的門市：<strong>' . esc_html( $store_data['name'] ) . '</strong></p>';
		$html .= '<p style="color: #666; margin-bottom: 20px;">門市地址：' . esc_html( $store_data['address'] ) . '</p>';
		$html .= '<p style="color: #888; font-size: 14px;">正在返回結帳頁面...</p>';
		$html .= '</div>';
		
		$html .= '<form method="post" id="payuni-store-redirect" action="' . esc_url( $checkout_url ) . '">';
		$html .= '<input type="hidden" name="payuni_selected_store_id" value="' . esc_attr( $store_data['id'] ) . '">';
		$html .= '<input type="hidden" name="payuni_selected_store_name" value="' . esc_attr( $store_data['name'] ) . '">';
		$html .= '<input type="hidden" name="payuni_selected_store_address" value="' . esc_attr( $store_data['address'] ) . '">';
		$html .= '<input type="hidden" name="payuni_selected_store_data" value="' . esc_attr( wp_json_encode( $store_data ) ) . '">';
		$html .= '</form>';
		$html .= '<script>setTimeout(function(){document.getElementById("payuni-store-redirect").submit();},1500);</script>';
		$html .= '</body></html>';

		echo wp_kses(
			$html,
			[
				'!doctype' => [], 'html' => [ 'lang' => true ], 'head' => [], 'meta' => [ 'charset' => true ], 'title' => [],
				'style' => [], 'body' => [], 'div' => [ 'style' => true ], 'h3' => [ 'style' => true ], 'p' => [ 'style' => true ], 'strong' => [],
				'form' => [ 'method' => true, 'id' => true, 'action' => true ],
				'input' => [ 'type' => true, 'name' => true, 'value' => true ],
				'script' => [],
			]
		);
		exit;
	}

	public static function handle_admin_store_map_return() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mo-ectools' ), 403 );
		}
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'payuni_admin_store_' . $order_id ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'mo-ectools' ), 403 );
		}

		// 獲取門市資料
		$store_data = array(
			'CVSStoreID'    => isset( $_POST['CVSStoreID'] ) ? sanitize_text_field( wp_unslash( $_POST['CVSStoreID'] ) ) : '',
			'CVSStoreName'  => isset( $_POST['CVSStoreName'] ) ? sanitize_text_field( wp_unslash( $_POST['CVSStoreName'] ) ) : '',
			'CVSAddress'    => isset( $_POST['CVSAddress'] ) ? sanitize_text_field( wp_unslash( $_POST['CVSAddress'] ) ) : '',
		);

		// 更新訂單 meta
		$order = wc_get_order( $order_id );
		if ( $order ) {
			// 準備統一的 JSON 格式資料（v2.0）
			$unified_store_data = array(
				'id'        => $store_data['CVSStoreID'],
				'name'      => $store_data['CVSStoreName'],
				'address'   => $store_data['CVSAddress'],
			);
			
			// 儲存統一的 JSON 格式（主要格式）
			$order->update_meta_data( OrderMeta::STORE_DATA_JSON, wp_json_encode( $unified_store_data ) );
			
			// 舊格式儲存（向下相容）
			$order->update_meta_data( OrderMeta::StoreId, $store_data['CVSStoreID'] );
			$order->update_meta_data( OrderMeta::StoreName, $store_data['CVSStoreName'] );
			$order->update_meta_data( OrderMeta::StoreAddr, $store_data['CVSAddress'] );
			
			$order->save();
		}

		// 跳轉回訂單編輯頁面
		wp_safe_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit&payuni_store_updated=1' ) );
		exit;
	}

	public static function ajax_get_store_data() {
		$cid = WC()->session ? WC()->session->get_customer_id() : '(no session)';
		PayuniShipping::log( 'ajax_get_store_data called; customer_id=' . $cid . '; nonce=' . ( isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : 'missing' ) );
		check_ajax_referer( 'payuni_store_search', 'nonce' );

		$store_data = WC()->session ? WC()->session->get( 'payuni_selected_store_data', null ) : null;
		PayuniShipping::log( 'ajax_get_store_data result: ' . wc_print_r( $store_data, true ) );

		if ( $store_data ) {
			wp_send_json_success( $store_data );
		} else {
			wp_send_json_error( array( 'message' => '沒有已選擇的門市' ) );
		}
	}

	public static function ajax_resolve_store_token() {
		check_ajax_referer( 'payuni_store_search', 'nonce' );
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		if ( strlen( $token ) < 16 ) {
			wp_send_json_error( array( 'message' => '無效的 token' ), 400 );
		}
		$store_data = get_transient( 'mowp_payuni_store_' . $token );
		PayuniShipping::log( 'ajax_resolve_store_token; token=' . substr( $token, 0, 8 ) . '…; data=' . wc_print_r( $store_data, true ) );

		if ( ! is_array( $store_data ) || ! isset( $store_data['id'] ) || empty( $store_data['id'] ) ) {
			wp_send_json_error( array( 'message' => '門市資料尚未準備好或已過期' ), 404 );
		}

		// Mirror into the visitor's actual session so order-create flow picks
		// it up, and delete the transient (one-shot semantics).
		if ( WC()->session ) {
			WC()->session->set( 'payuni_selected_store_data', $store_data );
		}
		delete_transient( 'mowp_payuni_store_' . $token );

		wp_send_json_success( $store_data );
	}

	public static function ajax_clear_store_data() {
		check_ajax_referer( 'payuni_store_search', 'nonce' );

		WC()->session->set( 'payuni_selected_store_data', null );

		wp_send_json_success( array( 'message' => '門市資料已清除' ) );
	}

	public static function save_store_selection( $order, $data ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- WC checkout submit nonce 'woocommerce-process-checkout-nonce' verified by WC core in WC_Checkout::process_checkout() before this callback fires.
		// 記錄方法呼叫用於除錯
		PayuniShipping::log( 'save_store_selection called with HPOS-compatible hook' );
		
		// 取得運送方式
		$shipping_methods = $order->get_shipping_methods();
		$shipping_method_id = '';
		
		foreach ( $shipping_methods as $shipping_method ) {
			$shipping_method_id = $shipping_method->get_method_id();
			break; // 只取第一個運送方式
		}
		
		PayuniShipping::log( 'Shipping method ID: ' . $shipping_method_id );
		// 如果不是超商取貨，就不需要處理門市資料
		if ( strpos( $shipping_method_id, 'mo_payuni_shipping_711' ) === false ) {
			PayuniShipping::log( 'Not a CVS shipping method, skipping store data save' );
			return;
		}
		
		// 儲存運送類型相關的 metadata
		if ( ! empty( $shipping_method_id ) ) {
			// 取得 LgsType
			$lgs_type = LgsType::get_lgs_type_by_shipping_method( $shipping_method_id );
			if ( ! empty( $lgs_type ) ) {
				$order->update_meta_data( OrderMeta::LgsType, $lgs_type );
				PayuniShipping::log( 'Saved LgsType: ' . $lgs_type );
			}
			
			// 取得 ShipType
			$ship_type = ShipType::get_ship_type( $shipping_method_id );
			if ( ! empty( $ship_type ) ) {
				$order->update_meta_data( OrderMeta::ShipType, $ship_type );
				PayuniShipping::log( 'Saved ShipType: ' . $ship_type );
			}
			
			// 決定 GoodsType
			$goods_type = GoodsType::NORMAL; // 預設常溫
			if ( strpos( $shipping_method_id, '_frozen' ) !== false ) {
				$goods_type = GoodsType::FROZEN;
			} elseif ( strpos( $shipping_method_id, '_refrigerated' ) !== false ) {
				$goods_type = GoodsType::REFRIGERATED;
			}
			$order->update_meta_data( OrderMeta::GoodsType, $goods_type );
			PayuniShipping::log( 'Saved GoodsType: ' . $goods_type . ' (' . GoodsType::get_name( $goods_type ) . ')' );

			if ( isset( $data['shipping_phone'] ) ) {
				if ( version_compare(  Constants::get_constant( 'WC_VERSION' ), '5.6.0', '<' ) ) {
					$order->update_meta_data( '_shipping_phone', $data['shipping_phone'] );
				}
			}
	
			if ( $order->get_payment_method() === 'cod' ) {
				$order->update_meta_data( OrderMeta::ServiceType, ServiceType::COD );
				$order->update_meta_data( OrderMeta::TradeAmt, ShippingRequest::get_trade_amt( $order ) );
			} else {
				$order->update_meta_data( OrderMeta::ServiceType, ServiceType::NOT_COD );
				$order->update_meta_data( OrderMeta::TradeAmt, ShippingRequest::get_trade_amt( $order ) );
			}
		}
	
		// 記錄所有 POST 資料以便除錯
		PayuniShipping::log( 'All POST data keys: ' . implode( ', ', array_keys( $_POST ) ) );
		
		// 首先從 POST 取得資料
		$selected_store_id   = isset( $_POST['payuni_selected_store_id'] ) ? sanitize_text_field( wp_unslash( $_POST['payuni_selected_store_id'] ) ) : '';
		$selected_store_data = isset( $_POST['payuni_selected_store_data'] ) ? sanitize_textarea_field( wp_unslash( $_POST['payuni_selected_store_data'] ) ) : '';

		// 如果沒有 POST 資料，嘗試從 session 取得並進行安全檢查
		if ( ( empty( $selected_store_id ) || empty( $selected_store_data ) ) && WC()->session ) {
			try {
				$stored_data = WC()->session->get( 'payuni_selected_store_data', null );
				if ( $stored_data && is_array( $stored_data ) ) {
					$selected_store_id = $stored_data['id'] ?? '';
					$selected_store_data = wp_json_encode( $stored_data );
					
					PayuniShipping::log( sprintf( 
						'Retrieved from session - Store ID: %s', 
						$selected_store_id 
					) );
				}
			} catch ( \Exception $e ) {
				PayuniShipping::log( 'Error accessing session: ' . $e->getMessage() );
			}
		}

		// 如果仍然沒有資料則返回
		if ( empty( $selected_store_id ) || empty( $selected_store_data ) ) {
			PayuniShipping::log( 'No store selection data found in POST or session' );
			return;
		}

		// 驗證 JSON 資料
		$store_data = json_decode( $selected_store_data, true );
		
		// 檢查 JSON 解碼錯誤
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			PayuniShipping::log( 'JSON decode error: ' . json_last_error_msg() );
			return;
		}
		
		if ( ! is_array( $store_data ) || empty( $store_data['id'] ) ) {
			PayuniShipping::log( 'Invalid store data format or missing store ID' );
			return;
		}
		
		// 記錄成功取得的門市資料
		PayuniShipping::log( sprintf( 'Saving store data - Store ID: %s, Name: %s', $store_data['id'], $store_data['name'] ) );
		
		// 準備統一的 JSON 格式資料（v2.0）
		$unified_store_data = array(
			'id'        => $store_data['id'],
			'name'      => $store_data['name'],
			'address'   => $store_data['address'],
		);
		
		// 儲存統一的 JSON 格式（主要格式）
		$order->update_meta_data( OrderMeta::STORE_DATA_JSON, wp_json_encode( $unified_store_data ) );
		
		// 舊格式儲存（向下相容）
		$order->update_meta_data( OrderMeta::StoreId, $store_data['id'] );
		$order->update_meta_data( OrderMeta::StoreName, $store_data['name'] );
		$order->update_meta_data( OrderMeta::StoreAddr, $store_data['address'] );

		// 寫入 canonical _mo_shipping_cvs_* meta（CLAUDE.md §3.5 要求 — 跨 provider 共用 key）
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_ID, (string) $store_data['id'] );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_NAME, (string) $store_data['name'] );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_ADDRESS, (string) $store_data['address'] );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_PROVIDER, 'payuni' );

		// HPOS 下 woocommerce_checkout_create_order hook 階段 WC 會統一 save，不需手動。

		PayuniShipping::log( 'Store data saved in JSON format: ' . wc_print_r( $unified_store_data, true ) );
		
		// 清除 session 中的門市資料
		if ( WC()->session ) {
			WC()->session->set( 'payuni_selected_store_data', null );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		do_action( 'mo_payuni_shipping_save_cvs_order_meta', $order, $data );
	}

	public static function add_store_data_fragment( $fragments ) {
		$stored_store_data = null;
		if ( WC()->session ) {
			$stored_store_data = WC()->session->get( 'payuni_selected_store_data', null );
		}
		
		if ( $stored_store_data ) {
			$fragments['payuni_stored_data'] = $stored_store_data;
			PayuniShipping::log( 'PAYUNi Store Selector: Adding store data to fragments: ' . wc_print_r( $stored_store_data, true ) );
		}
		
		// 不需要添加 HTML fragment，因為 PHP hook 會自動處理
		// 移除這部分以避免重複處理和無限循環
		
		return $fragments;
	}

	public static function get_order_store_data( $order_id ) {
		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			return null;
		}

		// 1. 優先讀取新的統一 JSON 格式（v2.0）
		$json_data = $order->get_meta( OrderMeta::STORE_DATA_JSON );
		if ( ! empty( $json_data ) ) {
			$store_data = json_decode( $json_data, true );
			if ( is_array( $store_data ) && ! empty( $store_data['id'] ) ) {
				// 返回統一格式
				return array(
					'store_id'       => $store_data['id'],
					'store_name'     => $store_data['name'],
					'store_address'  => $store_data['address'],
					'store_telephone'=> $store_data['telephone'] ?? '',
					'store_outside'  => $store_data['outside'] ?? '0',
					'store_ship'     => $store_data['ship'] ?? '',
					'version'        => $store_data['version'] ?? '2.0',
				);
			}
		}

		// 2. 嘗試從舊格式讀取並遷移
		$legacy_data = self::read_legacy_store_data( $order );
		
		if ( $legacy_data ) {
			// 自動遷移到新格式
			$migrated_data = self::migrate_legacy_store_data( $order, $legacy_data );
			
			if ( $migrated_data ) {
				PayuniShipping::log( 'Auto-migrated store data to v2.0 format for order #' . $order_id );
				return $migrated_data;
			}
		}

		return null;
	}
	
	private static function read_legacy_store_data( $order ) {
		// 讀取舊的個別欄位格式
		$old_store_id = $order->get_meta( OrderMeta::StoreId );
		if ( ! empty( $old_store_id ) ) {
			return array(
				'id'        => $old_store_id,
				'name'      => $order->get_meta( OrderMeta::StoreName ),
				'address'   => $order->get_meta( OrderMeta::StoreAddr ),
			);
		}

		return null;
	}
	
	private static function migrate_legacy_store_data( $order, $legacy_data ) {
		if ( empty( $legacy_data['id'] ) ) {
			return null;
		}
		
		// 準備統一格式資料
		$unified_data = array(
			'id'        => $legacy_data['id'],
			'name'      => $legacy_data['name'] ?? '',
			'address'   => $legacy_data['address'] ?? '',
		);
		
		// 儲存到新格式
		$order->update_meta_data( OrderMeta::STORE_DATA_JSON, wp_json_encode( $unified_data ) );
		$order->save();
		
		// 返回統一格式
		return array(
			'store_id'       => $unified_data['id'],
			'store_name'     => $unified_data['name'],
			'store_address'  => $unified_data['address'],
		);
	}

	public static function update_session_shipping_method( $posted_data ) {
		parse_str( (string) $posted_data, $data );

		if ( ! isset( $data['shipping_method'] ) || ! is_array( $data['shipping_method'] ) || ! WC()->session ) {
			return;
		}

		$clean = array();
		foreach ( $data['shipping_method'] as $package_idx => $method ) {
			// shipping method ids are word chars + colon (e.g. "mo_payuni_shipping_711_b2c_normal:3").
			$method = (string) $method;
			if ( '' === $method || ! preg_match( '/^[A-Za-z0-9_:\\-]+$/', $method ) ) {
				continue;
			}
			$clean[ (int) $package_idx ] = sanitize_text_field( wp_unslash( $method ) );
		}

		WC()->session->set( 'chosen_shipping_methods', $clean );
	}

	public static function display_store_selector_after_shipping() {
		// 檢查是否選擇了 PAYUNi 超商取貨運送方式
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		PayuniShipping::log( 'display_store_selector_after_shipping fired; chosen=' . wc_print_r( $chosen_methods, true ) );

		if ( empty( $chosen_methods ) ) {
			PayuniShipping::log( 'display_store_selector: bail — chosen_methods empty' );
			return;
		}

		$shipping_method = $chosen_methods[0];
		$method_id = strpos( $shipping_method, ':' ) !== false ? explode( ':', $shipping_method )[0] : $shipping_method;

		// 只有選擇 PAYUNi 超商取貨時才顯示
		if ( ! PayuniShipping::needs_cvs( $method_id ) ) {
			PayuniShipping::log( 'display_store_selector: bail — needs_cvs(' . $method_id . ') false' );
			return;
		}
		PayuniShipping::log( 'display_store_selector: rendering for method=' . $method_id );

		// 優先從 POST 資料恢復到 Session（處理從超商地圖返回的情況）
		$stored_store_data = self::restore_store_data_from_post();
		
		// 獲取已選擇的門市資料
		$stored_store_data = WC()->session->get( 'payuni_selected_store_data', array() );

		?>
		<tr class="payuni-store-selector-row payuni-layout-<?php echo esc_attr( PayuniShipping::$cvs_selector_layout ); ?>">
			<?php if ( PayuniShipping::$cvs_selector_layout === 'two_column' ) : ?>
				<th class="payuni-store-selector-label">
					<?php esc_html_e( '超商門市', 'mo-ectools' ); ?>
				</th>
				<td class="payuni-store-selector-content">
					<div class="payuni-store-selector">
						<?php self::render_store_selector_content( $stored_store_data ); ?>
					</div>
				</td>
			<?php else : ?>
				<td colspan="2">
					<div class="payuni-select-store-heading"><?php esc_html_e( '超商門市', 'mo-ectools' ); ?></div>
					<div class="payuni-store-selector">
						<?php self::render_store_selector_content( $stored_store_data ); ?>
					</div>
				</td>
			<?php endif; ?>
		</tr>
		<?php
	}

	private static function render_store_selector_content( $stored_store_data ) {
		// 如果 Session 沒有資料，嘗試從 POST 恢復
		if ( empty( $stored_store_data ) ) {
			self::restore_store_data_from_post();
			$stored_store_data = WC()->session->get( 'payuni_selected_store_data', array() );
		}
		
		// Log for debugging
		PayuniShipping::log( 'render_store_selector_content - stored_store_data: ' . wc_print_r( $stored_store_data, true ) );
		
		if ( ! empty( $stored_store_data ) && is_array( $stored_store_data ) ) : ?>
			<div class="payuni-selected-store">
				<div class="store-info">
					<div class="store-name"><?php echo esc_html( $stored_store_data['name'] ?? '' ); ?></div>
					<div class="store-address"><?php echo esc_html( $stored_store_data['address'] ?? '' ); ?></div>
					<div class="store-meta">
						<?php /* translators: %s: convenience store ID */ ?>
						<span class="store-id"><?php echo esc_html( sprintf( __( '門市代號: %s', 'mo-ectools' ), $stored_store_data['id'] ?? '' ) ); ?></span>
						<?php if ( isset( $stored_store_data['outside'] ) && ( $stored_store_data['outside'] === '1' || $stored_store_data['outside'] === 1 ) ) : ?>
							<span class="store-outside">⚠ <?php esc_html_e( '離島地區', 'mo-ectools' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<button type="button" class="payuni-store-map-btn button"><?php esc_html_e( '更換門市', 'mo-ectools' ); ?></button>
			</div>
		<?php else : ?>
			<div class="payuni-no-store">
				<p style="margin-bottom: 10px; color: #856404;"><?php esc_html_e( '尚未選擇取貨門市', 'mo-ectools' ); ?></p>
				<button type="button" class="payuni-store-map-btn button"><?php esc_html_e( '選擇門市', 'mo-ectools' ); ?></button>
			</div>
		<?php endif;
	}
	
	private static function restore_store_data_from_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- External store callback POST; hash verified inside this method.

		if ( ! isset( $_POST['payuni_selected_store_id'] ) || ! isset( $_POST['payuni_selected_store_name'] ) || ! isset( $_POST['payuni_selected_store_address'] ) ) {
			return false;
		}

		PayuniShipping::log( 'restore_store_data_from_post: ' . wc_print_r( $_POST, true ) );

		// Check if we have store selection POST data
		$store_id      = isset( $_POST['payuni_selected_store_id'] ) ? sanitize_text_field( wp_unslash( $_POST['payuni_selected_store_id'] ) ) : '';
		$store_name    = isset( $_POST['payuni_selected_store_name'] ) ? sanitize_text_field( wp_unslash( $_POST['payuni_selected_store_name'] ) ) : '';
		$store_address = isset( $_POST['payuni_selected_store_address'] ) ? sanitize_text_field( wp_unslash( $_POST['payuni_selected_store_address'] ) ) : '';
		// Use wp_unslash for JSON data to preserve structure
		$store_data_json = isset( $_POST['payuni_selected_store_data'] ) ? sanitize_textarea_field( wp_unslash( $_POST['payuni_selected_store_data'] ) ) : '';

		// Store the data in WooCommerce session
		$store_data = array(
			'id'        => $store_id,
			'name'      => $store_name,
			'address'   => $store_address,
		);

		// Use JSON data if available, otherwise use individual fields
		if ( ! empty( $store_data_json ) ) {
			$parsed_data = json_decode( $store_data_json, true );
			if ( json_last_error() === JSON_ERROR_NONE && $parsed_data ) {
				$store_data = $parsed_data;
				PayuniShipping::log( 'Successfully parsed store JSON data from POST' );
			} else {
				PayuniShipping::log( 'JSON decode error from POST: ' . json_last_error_msg() );
			}
		}

		// Store in session
		WC()->session->set( 'payuni_selected_store_data', $store_data );
		
		// Log for debugging
		PayuniShipping::log( 'Store data restored from POST to WC Session: ' . wc_print_r( $store_data, true ) );
		return WC()->session->get( 'payuni_selected_store_data', array() );
	
	}

	public static function modify_billing_fields_for_cvs( $fields ) {
		// Check if the setting is enabled
		if ( get_option( 'mo_payuni_shipping_hide_billing_address_fields', 'no' ) !== 'yes' ) {
			return $fields;
		}

		// Check if CVS shipping is selected
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		if ( empty( $chosen_methods ) ) {
			return $fields;
		}

		$shipping_method = $chosen_methods[0];
		$method_id       = strpos( $shipping_method, ':' ) !== false ? explode( ':', $shipping_method )[0] : $shipping_method;

		// Only modify if PAYUNi CVS shipping is selected
		if ( ! PayuniShipping::needs_cvs( $method_id ) ) {
			return $fields;
		}

		// Remove required validation for billing address fields
		$address_fields = array(
			'billing_country',
			'billing_postcode',
			'billing_state',
			'billing_city',
			'billing_address_1',
			'billing_address_2',
			'billing_company',
		);

		foreach ( $address_fields as $field ) {
			if ( isset( $fields['billing'][ $field ] ) ) {
				$fields['billing'][ $field ]['required'] = false;
				// FIX: do NOT default to 'N/A'. The original wpbr code did this to
				// pass server validation, but the placeholder string stuck around
				// and reappeared as a pre-filled value when the customer later
				// switched to home delivery (TCat/etc). Use empty string instead.
				if ( ! isset( $fields['billing'][ $field ]['default'] ) ) {
					$fields['billing'][ $field ]['default'] = '';
				} elseif ( 'N/A' === $fields['billing'][ $field ]['default'] ) {
					$fields['billing'][ $field ]['default'] = '';
				}
			}
		}

		PayuniShipping::log( 'Modified billing fields for CVS shipping - removed required validation' );

		return $fields;
	}

	public static function set_default_billing_address_for_cvs( $data ) {
		// Check if the setting is enabled
		if ( get_option( 'mo_payuni_shipping_hide_billing_address_fields', 'no' ) !== 'yes' ) {
			return $data;
		}

		// Check if CVS shipping is selected
		if ( empty( $data['shipping_method'] ) ) {
			return $data;
		}

		$shipping_method = is_array( $data['shipping_method'] ) ? $data['shipping_method'][0] : $data['shipping_method'];
		$method_id       = strpos( $shipping_method, ':' ) !== false ? explode( ':', $shipping_method )[0] : $shipping_method;

		// Only modify if PAYUNi CVS shipping is selected
		if ( ! PayuniShipping::needs_cvs( $method_id ) ) {
			return $data;
		}

		// Force-clear billing + shipping address fields on CVS submit. CVS 包裹
		// 送門市，家裡地址完全不需要進訂單（包含 returning customer 的
		// saved profile autofill 跟 Block 結帳沒做地址 hide UI 的情境）。
		$fields_to_clear = array(
			// billing
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_company',
			// shipping — 整段砍光，保留 first_name / last_name / phone（門市取件需要受件人）
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
			'shipping_company',
		);
		foreach ( $fields_to_clear as $field ) {
			$data[ $field ] = '';
		}

		// Ensure billing country is set (required for order processing)
		if ( empty( $data['billing_country'] ) || $data['billing_country'] === 'N/A' ) {
			$data['billing_country'] = WC()->countries->get_base_country();
		}

		PayuniShipping::log( 'Set default billing address for CVS shipping' );

		return $data;
	}
}
