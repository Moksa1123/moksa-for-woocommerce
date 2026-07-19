<?php
namespace Moksafowo\Modules\PayuniShipping\Frontend;

use Automattic\Jetpack\Constants;
use Moksafowo\Modules\Payuni\Credentials;
use Moksafowo\Modules\PayuniShipping\Api\ShippingRequest;
use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;
use Moksafowo\Order\Meta\Keys;
use Moksafowo\Modules\PayuniShipping\Utils\LgsType;
use Moksafowo\Modules\PayuniShipping\Utils\GoodsType;
use Moksafowo\Modules\PayuniShipping\Utils\ServiceType;
use Moksafowo\Modules\PayuniShipping\Utils\ShipType;
use Moksafowo\Modules\PayuniShipping\Utils\SingletonTrait;

use Moksafowo\Modules\Shared\Frontend\Interstitial;

defined( 'ABSPATH' ) || exit;

class StoreSelector {

	use SingletonTrait;

	public static function init() {

		self::get_instance();

		add_action( 'woocommerce_review_order_after_shipping', array( self::get_instance(), 'display_store_selector_after_shipping' ) );

		// nopriv 必要：guest checkout 也要能開地圖；各 handler 以 nonce + session 綁定驗源
		add_action( 'wp_ajax_moksafowo_payuni_open_store_map', array( __CLASS__, 'ajax_open_store_map' ) );
		add_action( 'wp_ajax_nopriv_moksafowo_payuni_open_store_map', array( __CLASS__, 'ajax_open_store_map' ) );

		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_store_selection' ), 10, 2 );

		// Block Store API 不 fire woocommerce_checkout_create_order；
		// __experimental_calc_totals=true 的試算 call 不能清 session（否則真下單找不到 store）
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

		add_action( 'wp_ajax_moksafowo_payuni_get_store_data', array( __CLASS__, 'ajax_get_store_data' ) );
		add_action( 'wp_ajax_nopriv_moksafowo_payuni_get_store_data', array( __CLASS__, 'ajax_get_store_data' ) );

		// PAYUNi cross-site POST 在 SameSite=Lax 下會丟 session cookie，
		// 以 30min transient + token 讓 Block JS 在返回後 resolve 門市資料
		add_action( 'wp_ajax_moksafowo_payuni_resolve_store_token', array( __CLASS__, 'ajax_resolve_store_token' ) );
		add_action( 'wp_ajax_nopriv_moksafowo_payuni_resolve_store_token', array( __CLASS__, 'ajax_resolve_store_token' ) );

		add_action( 'wp_ajax_moksafowo_payuni_clear_store_data', array( __CLASS__, 'ajax_clear_store_data' ) );
		add_action( 'wp_ajax_nopriv_moksafowo_payuni_clear_store_data', array( __CLASS__, 'ajax_clear_store_data' ) );

		add_action( 'woocommerce_api_moksafowo_payuni_store_callback', array( __CLASS__, 'handle_store_map_return' ) );
		add_action( 'woocommerce_api_moksafowo_payuni_admin_store_callback', array( __CLASS__, 'handle_admin_store_map_return' ) );

		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'add_store_data_fragment' ) );

		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'update_session_shipping_method' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'modify_billing_fields_for_cvs' ), 20 );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'set_default_billing_address_for_cvs' ), 10 );
		// Block 不走 woocommerce_checkout_posted_data；pri 100 確保 WC core 處理完才清地址
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'strip_na_placeholder_for_home_delivery' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'clear_addresses_for_cvs_order' ), 100, 2 );
	}

	public static function clear_addresses_for_cvs_order( \WC_Order $order, array $data ): void {
		if ( get_option( 'moksafowo_payuni_shipping_hide_billing_address_fields', 'no' ) !== 'yes' ) {
			return;
		}
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
		$hide_billing    = get_option( 'moksafowo_payuni_shipping_hide_billing_address_fields', 'no' ) === 'yes';

		// CVS + 隱藏設定：拒 autofill（避免 returning customer saved profile 灌進隱藏欄位）
		if ( $is_cvs && $hide_billing ) {
			return '';
		}

		if ( ! $is_cvs && 'N/A' === $value ) {
			return '';
		}

		return $value;
	}

	public static function enqueue_scripts() {
		// is_checkout() 只對 WC 設定的結帳頁返回 true；shortcode / Block 頁需另判
		$post         = get_post();
		$has_checkout = false;
		if ( $post instanceof \WP_Post ) {
			$has_checkout = has_block( 'woocommerce/checkout', $post )
				|| has_block( 'woocommerce/classic-shortcode', $post )
				|| has_shortcode( $post->post_content, 'woocommerce_checkout' );
		}
		if ( ! is_checkout() && ! $has_checkout ) {
			return;
		}

		if ( ! WC()->cart || ! WC()->cart->needs_shipping() ) {
			return;
		}

		wp_enqueue_script( 'moksafowo-payuni-store-selector', ( MOKSAFOWO_PLUGIN_URL . 'src/Modules/PayuniShipping/' ) . 'assets/js/store-selector.js', array( 'jquery' ), MOKSAFOWO_VERSION, true );
		wp_enqueue_style( 'moksafowo-payuni-store-selector', ( MOKSAFOWO_PLUGIN_URL . 'src/Modules/PayuniShipping/' ) . 'assets/css/store-selector.css', array(), MOKSAFOWO_VERSION );

		if ( $post && has_block( 'woocommerce/checkout', $post ) ) {
			$path    = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/PayuniShipping/assets/js/block-checkout-store.js';
			$version = file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOWO_VERSION;
			\Moksafowo\Modules\Shared\Frontend\CvsStoreAssets::enqueue();
			wp_enqueue_script(
				'moksafowo-payuni-block-checkout-store',
				( MOKSAFOWO_PLUGIN_URL . 'src/Modules/PayuniShipping/' ) . 'assets/js/block-checkout-store.js',
				array( \Moksafowo\Modules\Shared\Frontend\CvsStoreAssets::SCRIPT ),
				$version,
				true
			);
			wp_localize_script(
				'moksafowo-payuni-block-checkout-store',
				'moksafowo_payuni_block',
				array(
					'ajax_url'          => admin_url( 'admin-ajax.php' ),
					'search_nonce'      => wp_create_nonce( 'moksafowo_payuni_store_search' ),
					'cvs_method_prefix' => 'moksafowo_payuni_shipping_711',
					'i18n'              => array(
						'select'         => __( '選擇門市', 'moksa-for-woocommerce' ),
						'change'         => __( '更換門市', 'moksa-for-woocommerce' ),
						'none'           => __( '尚未選擇取貨門市', 'moksa-for-woocommerce' ),
						'openMap'        => __( '開啟超商地圖', 'moksa-for-woocommerce' ),
						'loading'        => __( '載入中…', 'moksa-for-woocommerce' ),
						'error'          => __( '載入失敗，請稍後再試', 'moksa-for-woocommerce' ),
						'label'          => __( '已選門市', 'moksa-for-woocommerce' ),
						'store_id_label' => __( '門市代號:', 'moksa-for-woocommerce' ),
					),
				)
			);
		}

		// Classic 換金流需重算運費（CVS 選項可能 COD-only）；Block 自行處理
		wp_add_inline_script(
			'moksafowo-payuni-store-selector',
			<<<'JS'
jQuery(function($){$('form.checkout').on('change','input[name="payment_method"]',function(){$(document.body).trigger('update_checkout');});});
JS
		);

		wp_enqueue_script( 'moksafowo-payuni-save-fields', ( MOKSAFOWO_PLUGIN_URL . 'src/Modules/PayuniShipping/' ) . 'assets/js/save-fields.js', array( 'jquery' ), MOKSAFOWO_VERSION, true );

		$stored_store_data = null;
		if ( WC()->session ) {
			$stored_store_data = WC()->session->get( 'moksafowo_payuni_selected_store_data', null );
		}

		wp_localize_script(
			'moksafowo-payuni-store-selector',
			'moksafowo_payuni_store_selector',
			array(
				'ajax_url'                    => admin_url( 'admin-ajax.php' ),
				'nonce'                       => wp_create_nonce( 'moksafowo_payuni_store_search' ),
				'return_url'                  => home_url( '/?payuni_store_return=1' ),
				'stored_store_data'           => $stored_store_data,
				'hide_billing_address_fields' => get_option( 'moksafowo_payuni_shipping_hide_billing_address_fields', 'no' ) === 'yes',
				'labels'                      => array(
					'select_store'      => __( '選擇門市', 'moksa-for-woocommerce' ),
					'change_store'      => __( '更換門市', 'moksa-for-woocommerce' ),
					'no_store_selected' => __( '尚未選擇門市', 'moksa-for-woocommerce' ),
					'open_map'          => __( '選擇門市', 'moksa-for-woocommerce' ),
					'loading'           => __( '跳轉中...', 'moksa-for-woocommerce' ),
					'error'             => __( '載入失敗，請稍後再試', 'moksa-for-woocommerce' ),
				),
			)
		);
	}

	public static function ajax_open_store_map() {
		check_ajax_referer( 'moksafowo_payuni_store_search', 'nonce' );

		$shipping_method = isset( $_POST['shipping_method'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_method'] ) ) : '';

		$method_id = strpos( $shipping_method, ':' ) !== false ? explode( ':', $shipping_method )[0] : $shipping_method;

		// payload 綁定 session 已選運送方式，防 nonce 持有者偽造任意 method_id
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'message' => __( '購物車為空或會話過期。', 'moksa-for-woocommerce' ) ), 400 );
		}
		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		if ( ! in_array( $shipping_method, $chosen, true ) ) {
			PayuniShipping::log( 'StoreMap signing-oracle blocked: requested ' . $shipping_method . ' but chosen=' . wp_json_encode( $chosen ) );
			wp_send_json_error( array( 'message' => __( '選取的運送方式與購物車不符。', 'moksa-for-woocommerce' ) ), 403 );
		}

		if ( empty( $method_id ) || strpos( $method_id, 'moksafowo_payuni_shipping_711' ) === false ) {
			wp_send_json_error( array( 'message' => __( '請選擇超商取貨運送方式', 'moksa-for-woocommerce' ) ) );
		}

		// 一次性 token：callback 憑此區分合法發起與隨機 ?moksafowo_token= 注入；30min TTL
		$token = wp_generate_password( 24, false, false );
		set_transient( 'moksafowo_payuni_store_' . $token, array( 'pending' => true ), 30 * MINUTE_IN_SECONDS );
		$callback_url = add_query_arg( 'moksafowo_token', $token, WC()->api_request_url( 'moksafowo_payuni_store_callback' ) );

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

		$form_data = array(
			'MerID'       => Credentials::merchant_id(),
			'Version'     => '1.1',
			'EncryptInfo' => $encrypted_info,
			'HashInfo'    => $hash_info,
		);

		wp_send_json_success(
			array(
				'form_data' => $form_data,
				'api_url'   => PayuniShipping::$api_url . '/logistics/ship_map',
			)
		);
	}

	public static function handle_store_map_return() {
		// PAYUNi store-map callback: cross-site POST from PAYUNi; no WP nonce possible.
		// Source authenticity verified via HashInfo hash_equals (PayuniShipping::hash_info) before decryption.
		// All fields sanitized via wc_clean + wp_unslash at capture; MapJson content deep-sanitized via map_deep after json_decode.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- PAYUNi store-map callback; no WP nonce possible; source verified via HashInfo hash_equals before any data use; all fields sanitized via wc_clean and map_deep.
		$posted = wc_clean( wp_unslash( $_POST ) );
		PayuniShipping::log( 'PAYUNi Store Map Return Data: ' . wc_print_r( $posted, true ) );

		$store_data = array();

		$encrypt_info = $posted['EncryptInfo'] ?? '';
		$posted_hash  = $posted['HashInfo'] ?? '';

		// SECURITY: 處理門市資料前先驗 HashInfo 簽章（與 711/TCat 通知一致）
		if ( '' === $encrypt_info || '' === $posted_hash
			|| ! hash_equals( PayuniShipping::hash_info( (string) $encrypt_info ), strtoupper( (string) $posted_hash ) ) ) {
			PayuniShipping::log( 'PAYUNi store callback HashInfo mismatch — rejected.' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( isset( $posted['Status'] ) && 'SUCCESS' === $posted['Status'] ) {
			$decrypted_info = PayuniShipping::decrypt( $encrypt_info );

			if ( isset( $decrypted_info['MapJson'] ) ) {
				$map_data = json_decode( $decrypted_info['MapJson'], true );
				$map_data = is_array( $map_data )
					? map_deep( $map_data, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v )
					: array();

				$store_data = array(
					'id'      => $map_data['StoreID'] ?? '',
					'name'    => $map_data['StoreName'] ?? '',
					'address' => $map_data['Address'] ?? '',
				);
			}
		}

		if ( empty( $store_data['id'] ) ) {
			PayuniShipping::log( 'PAYUNi Store Map Return: Missing store data' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// PRIMARY: transient by token — PAYUNi cross-site POST 可能在新 session 跑，session key 不可靠
		$incoming_token = isset( $_GET['moksafowo_token'] ) ? sanitize_key( wp_unslash( $_GET['moksafowo_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PAYUNi store callback; HashInfo verified above; token is a one-shot transient lookup key, sanitized via sanitize_key.
		if ( strlen( $incoming_token ) >= 16 && get_transient( 'moksafowo_payuni_store_' . $incoming_token ) !== false ) {
			set_transient( 'moksafowo_payuni_store_' . $incoming_token, $store_data, 30 * MINUTE_IN_SECONDS );
			PayuniShipping::log( 'Store data saved to transient; token=' . substr( $incoming_token, 0, 8 ) . '…; data=' . wc_print_r( $store_data, true ) );
		} else {
			PayuniShipping::log( 'Store callback missing/invalid moksafowo_token (' . $incoming_token . ') — falling back to session only' );
		}

		// SECONDARY: session best-effort（部分瀏覽器仍保留 cookie，classic checkout 受益）
		$cid_before = WC()->session ? WC()->session->get_customer_id() : '(no session)';
		if ( WC()->session ) {
			WC()->session->set( 'moksafowo_payuni_selected_store_data', $store_data );
		}
		PayuniShipping::log( 'Store data saved to session (best-effort); customer_id=' . $cid_before );

		$checkout_url = wc_get_checkout_url();
		if ( $incoming_token !== '' ) {
			$checkout_url = add_query_arg( 'moksafowo_store', $incoming_token, $checkout_url );
		}

		$forms_html = '<form method="post" id="moksafowo-payuni-store-redirect" action="' . esc_url( $checkout_url ) . '">'
			. '<input type="hidden" name="moksafowo_payuni_selected_store_id" value="' . esc_attr( $store_data['id'] ) . '">'
			. '<input type="hidden" name="moksafowo_payuni_selected_store_name" value="' . esc_attr( $store_data['name'] ) . '">'
			. '<input type="hidden" name="moksafowo_payuni_selected_store_address" value="' . esc_attr( $store_data['address'] ) . '">'
			. '<input type="hidden" name="moksafowo_payuni_selected_store_data" value="' . esc_attr( wp_json_encode( $store_data ) ) . '">'
			. '<input type="hidden" name="moksafowo_payuni_store_nonce" value="' . esc_attr( wp_create_nonce( 'moksafowo_payuni_restore_store' ) ) . '">'
			. '</form>';

		Interstitial::render(
			__( '正在返回結帳頁面...', 'moksa-for-woocommerce' ),
			__( '門市選擇完成', 'moksa-for-woocommerce' ),
			[
				/* translators: %s: store name */
				sprintf( __( '選擇的門市：%s', 'moksa-for-woocommerce' ), '<strong>' . esc_html( $store_data['name'] ) . '</strong>' ),
				/* translators: %s: store address */
				sprintf( __( '門市地址：%s', 'moksa-for-woocommerce' ), esc_html( $store_data['address'] ) ),
				__( '正在返回結帳頁面...', 'moksa-for-woocommerce' ),
			],
			$forms_html,
			'setTimeout(function(){document.getElementById("moksafowo-payuni-store-redirect").submit();},1500);'
		);
		exit;
	}

	public static function handle_admin_store_map_return() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'moksa-for-woocommerce' ), 403 );
		}
		$nonce    = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'moksafowo_payuni_admin_store_' . $order_id ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'moksa-for-woocommerce' ), 403 );
		}

		$store_data = array(
			'CVSStoreID'   => isset( $_POST['CVSStoreID'] ) ? sanitize_text_field( wp_unslash( $_POST['CVSStoreID'] ) ) : '',
			'CVSStoreName' => isset( $_POST['CVSStoreName'] ) ? sanitize_text_field( wp_unslash( $_POST['CVSStoreName'] ) ) : '',
			'CVSAddress'   => isset( $_POST['CVSAddress'] ) ? sanitize_text_field( wp_unslash( $_POST['CVSAddress'] ) ) : '',
		);

		$order = wc_get_order( $order_id );
		if ( $order ) {
			$unified_store_data = array(
				'id'      => $store_data['CVSStoreID'],
				'name'    => $store_data['CVSStoreName'],
				'address' => $store_data['CVSAddress'],
			);

			$order->update_meta_data( OrderMeta::STORE_DATA_JSON, wp_json_encode( $unified_store_data ) );
			// 舊格式向下相容
			$order->update_meta_data( OrderMeta::StoreId, $store_data['CVSStoreID'] );
			$order->update_meta_data( OrderMeta::StoreName, $store_data['CVSStoreName'] );
			$order->update_meta_data( OrderMeta::StoreAddr, $store_data['CVSAddress'] );

			$order->save();
		}

		wp_safe_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit&payuni_store_updated=1' ) );
		exit;
	}

	public static function ajax_get_store_data() {
		check_ajax_referer( 'moksafowo_payuni_store_search', 'nonce' );
		$cid = WC()->session ? WC()->session->get_customer_id() : '(no session)';
		PayuniShipping::log( 'ajax_get_store_data called; customer_id=' . $cid );

		$store_data = WC()->session ? WC()->session->get( 'moksafowo_payuni_selected_store_data', null ) : null;
		PayuniShipping::log( 'ajax_get_store_data result: ' . wc_print_r( $store_data, true ) );

		if ( $store_data ) {
			wp_send_json_success( $store_data );
		} else {
			wp_send_json_error( array( 'message' => '沒有已選擇的門市' ) );
		}
	}

	public static function ajax_resolve_store_token() {
		check_ajax_referer( 'moksafowo_payuni_store_search', 'nonce' );
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		if ( strlen( $token ) < 16 ) {
			wp_send_json_error( array( 'message' => '無效的 token' ), 400 );
		}
		$store_data = get_transient( 'moksafowo_payuni_store_' . $token );
		PayuniShipping::log( 'ajax_resolve_store_token; token=' . substr( $token, 0, 8 ) . '…; data=' . wc_print_r( $store_data, true ) );

		if ( ! is_array( $store_data ) || ! isset( $store_data['id'] ) || empty( $store_data['id'] ) ) {
			wp_send_json_error( array( 'message' => '門市資料尚未準備好或已過期' ), 404 );
		}

		// mirror 進 session 供 order-create flow 使用，並刪除（one-shot）
		if ( WC()->session ) {
			WC()->session->set( 'moksafowo_payuni_selected_store_data', $store_data );
		}
		delete_transient( 'moksafowo_payuni_store_' . $token );

		wp_send_json_success( $store_data );
	}

	public static function ajax_clear_store_data() {
		check_ajax_referer( 'moksafowo_payuni_store_search', 'nonce' );

		WC()->session->set( 'moksafowo_payuni_selected_store_data', null );

		wp_send_json_success( array( 'message' => '門市資料已清除' ) );
	}

	public static function save_store_selection( $order, $data ) {
		// Bound to woocommerce_checkout_create_order / woocommerce_store_api_checkout_update_order_from_request.
		// WC core has already verified the 'woocommerce-process-checkout-nonce' nonce in WC_Checkout::process_checkout()
		// before this hook fires. $_POST fields used here are sanitized at point of use; JSON decoded then deep-sanitized.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- WC checkout nonce already verified by WC core before this hook fires; $_POST fields sanitized at use; JSON decoded then map_deep sanitized.
		PayuniShipping::log( 'save_store_selection called with HPOS-compatible hook' );

		$shipping_methods   = $order->get_shipping_methods();
		$shipping_method_id = '';

		foreach ( $shipping_methods as $shipping_method ) {
			$shipping_method_id = $shipping_method->get_method_id();
			break;
		}

		PayuniShipping::log( 'Shipping method ID: ' . $shipping_method_id );
		if ( strpos( $shipping_method_id, 'moksafowo_payuni_shipping_711' ) === false ) {
			PayuniShipping::log( 'Not a CVS shipping method, skipping store data save' );
			return;
		}

		if ( ! empty( $shipping_method_id ) ) {
			$lgs_type = LgsType::get_lgs_type_by_shipping_method( $shipping_method_id );
			if ( ! empty( $lgs_type ) ) {
				$order->update_meta_data( OrderMeta::LgsType, $lgs_type );
				PayuniShipping::log( 'Saved LgsType: ' . $lgs_type );
			}

			$ship_type = ShipType::get_ship_type( $shipping_method_id );
			if ( ! empty( $ship_type ) ) {
				$order->update_meta_data( OrderMeta::ShipType, $ship_type );
				PayuniShipping::log( 'Saved ShipType: ' . $ship_type );
			}

			$goods_type = GoodsType::NORMAL;
			if ( strpos( $shipping_method_id, '_frozen' ) !== false ) {
				$goods_type = GoodsType::FROZEN;
			} elseif ( strpos( $shipping_method_id, '_refrigerated' ) !== false ) {
				$goods_type = GoodsType::REFRIGERATED;
			}
			$order->update_meta_data( OrderMeta::GoodsType, $goods_type );
			PayuniShipping::log( 'Saved GoodsType: ' . $goods_type . ' (' . GoodsType::get_name( $goods_type ) . ')' );

			if ( isset( $data['shipping_phone'] ) ) {
				if ( version_compare( Constants::get_constant( 'WC_VERSION' ), '5.6.0', '<' ) ) {
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

		// save_store_selection runs on WC checkout save (woocommerce_checkout_create_order); WC core verified the checkout nonce upstream; values sanitized inline.
		PayuniShipping::log( 'All POST data keys: ' . implode( ', ', array_map( 'sanitize_text_field', array_map( 'wp_unslash', array_keys( $_POST ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- WC checkout nonce verified upstream; keys sanitized.

		$selected_store_id   = isset( $_POST['moksafowo_payuni_selected_store_id'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_payuni_selected_store_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- WC checkout nonce verified upstream.
		$selected_store_data = isset( $_POST['moksafowo_payuni_selected_store_data'] ) ? sanitize_textarea_field( wp_unslash( $_POST['moksafowo_payuni_selected_store_data'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- WC checkout nonce verified upstream.

		if ( ( empty( $selected_store_id ) || empty( $selected_store_data ) ) && WC()->session ) {
			try {
				$stored_data = WC()->session->get( 'moksafowo_payuni_selected_store_data', null );
				if ( $stored_data && is_array( $stored_data ) ) {
					$selected_store_id   = $stored_data['id'] ?? '';
					$selected_store_data = wp_json_encode( $stored_data );

					PayuniShipping::log( 'Retrieved from session - Store ID: ' . $selected_store_id );
				}
			} catch ( \Exception $e ) {
				PayuniShipping::log( 'Error accessing session: ' . $e->getMessage() );
			}
		}

		if ( empty( $selected_store_id ) || empty( $selected_store_data ) ) {
			PayuniShipping::log( 'No store selection data found in POST or session' );
			return;
		}

		$store_data = json_decode( $selected_store_data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			PayuniShipping::log( 'JSON decode error: ' . json_last_error_msg() );
			return;
		}

		if ( ! is_array( $store_data ) || empty( $store_data['id'] ) ) {
			PayuniShipping::log( 'Invalid store data format or missing store ID' );
			return;
		}

		// json_decode 不等於消毒：逐欄 sanitize 後才寫入 order meta。
		$store_data = map_deep( $store_data, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );

		PayuniShipping::log( sprintf( 'Saving store data - Store ID: %s, Name: %s', $store_data['id'], $store_data['name'] ) );

		$unified_store_data = array(
			'id'      => $store_data['id'],
			'name'    => $store_data['name'],
			'address' => $store_data['address'],
		);

		$order->update_meta_data( OrderMeta::STORE_DATA_JSON, wp_json_encode( $unified_store_data ) );
		// 舊格式向下相容
		$order->update_meta_data( OrderMeta::StoreId, $store_data['id'] );
		$order->update_meta_data( OrderMeta::StoreName, $store_data['name'] );
		$order->update_meta_data( OrderMeta::StoreAddr, $store_data['address'] );
		// 跨 provider 共用 canonical CVS meta key
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_ID, (string) $store_data['id'] );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_NAME, (string) $store_data['name'] );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_ADDRESS, (string) $store_data['address'] );
		$order->update_meta_data( Keys::SHIPPING_CVS_STORE_PROVIDER, 'payuni' );

		// HPOS 下 create_order hook 由 WC 統一 save，不需手動
		PayuniShipping::log( 'Store data saved in JSON format: ' . wc_print_r( $unified_store_data, true ) );

		if ( WC()->session ) {
			WC()->session->set( 'moksafowo_payuni_selected_store_data', null );
		}

		do_action( 'moksafowo_payuni_shipping_save_cvs_order_meta', $order, $data );
	}

	public static function add_store_data_fragment( $fragments ) {
		$stored_store_data = null;
		if ( WC()->session ) {
			$stored_store_data = WC()->session->get( 'moksafowo_payuni_selected_store_data', null );
		}

		if ( $stored_store_data ) {
			$fragments['moksafowo_payuni_stored_data'] = $stored_store_data;
			PayuniShipping::log( 'PAYUNi Store Selector: Adding store data to fragments: ' . wc_print_r( $stored_store_data, true ) );
		}

		return $fragments;
	}

	public static function get_order_store_data( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return null;
		}

		$json_data = $order->get_meta( OrderMeta::STORE_DATA_JSON );
		if ( ! empty( $json_data ) ) {
			$store_data = json_decode( $json_data, true );
			if ( is_array( $store_data ) && ! empty( $store_data['id'] ) ) {
				return array(
					'store_id'        => $store_data['id'],
					'store_name'      => $store_data['name'],
					'store_address'   => $store_data['address'],
					'store_telephone' => $store_data['telephone'] ?? '',
					'store_outside'   => $store_data['outside'] ?? '0',
					'store_ship'      => $store_data['ship'] ?? '',
					'version'         => $store_data['version'] ?? '2.0',
				);
			}
		}

		$legacy_data = self::read_legacy_store_data( $order );

		if ( $legacy_data ) {
			$migrated_data = self::migrate_legacy_store_data( $order, $legacy_data );

			if ( $migrated_data ) {
				PayuniShipping::log( 'Auto-migrated store data to v2.0 format for order #' . $order_id );
				return $migrated_data;
			}
		}

		return null;
	}

	private static function read_legacy_store_data( $order ) {
		$old_store_id = $order->get_meta( OrderMeta::StoreId );
		if ( ! empty( $old_store_id ) ) {
			return array(
				'id'      => $old_store_id,
				'name'    => $order->get_meta( OrderMeta::StoreName ),
				'address' => $order->get_meta( OrderMeta::StoreAddr ),
			);
		}

		return null;
	}

	private static function migrate_legacy_store_data( $order, $legacy_data ) {
		if ( empty( $legacy_data['id'] ) ) {
			return null;
		}

		$unified_data = array(
			'id'      => $legacy_data['id'],
			'name'    => $legacy_data['name'] ?? '',
			'address' => $legacy_data['address'] ?? '',
		);

		$order->update_meta_data( OrderMeta::STORE_DATA_JSON, wp_json_encode( $unified_data ) );
		$order->save();

		return array(
			'store_id'      => $unified_data['id'],
			'store_name'    => $unified_data['name'],
			'store_address' => $unified_data['address'],
		);
	}

	public static function update_session_shipping_method( $posted_data ) {
		parse_str( (string) $posted_data, $data );

		if ( ! isset( $data['shipping_method'] ) || ! is_array( $data['shipping_method'] ) || ! WC()->session ) {
			return;
		}

		$clean = array();
		foreach ( $data['shipping_method'] as $package_idx => $method ) {
			// shipping method ids are word chars + colon (e.g. "moksafowo_payuni_shipping_711_b2c_normal:3").
			$method = (string) $method;
			if ( '' === $method || ! preg_match( '/^[A-Za-z0-9_:\\-]+$/', $method ) ) {
				continue;
			}
			$clean[ (int) $package_idx ] = sanitize_text_field( wp_unslash( $method ) );
		}

		WC()->session->set( 'chosen_shipping_methods', $clean );
	}

	public static function display_store_selector_after_shipping() {
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		PayuniShipping::log( 'display_store_selector_after_shipping fired; chosen=' . wc_print_r( $chosen_methods, true ) );

		if ( empty( $chosen_methods ) ) {
			PayuniShipping::log( 'display_store_selector: bail — chosen_methods empty' );
			return;
		}

		$shipping_method = $chosen_methods[0];
		$method_id       = strpos( $shipping_method, ':' ) !== false ? explode( ':', $shipping_method )[0] : $shipping_method;

		if ( ! PayuniShipping::needs_cvs( $method_id ) ) {
			PayuniShipping::log( 'display_store_selector: bail — needs_cvs(' . $method_id . ') false' );
			return;
		}
		PayuniShipping::log( 'display_store_selector: rendering for method=' . $method_id );

		self::restore_store_data_from_post();
		$stored_store_data = WC()->session->get( 'moksafowo_payuni_selected_store_data', array() );

		?>
		<tr class="moksafowo-payuni-store-selector-row moksafowo-payuni-layout-<?php echo esc_attr( PayuniShipping::$cvs_selector_layout ); ?>">
			<?php if ( PayuniShipping::$cvs_selector_layout === 'two_column' ) : ?>
				<th class="moksafowo-payuni-store-selector-label">
					<?php esc_html_e( '超商門市', 'moksa-for-woocommerce' ); ?>
				</th>
				<td class="moksafowo-payuni-store-selector-content">
					<div class="moksafowo-payuni-store-selector">
						<?php self::render_store_selector_content( $stored_store_data ); ?>
					</div>
				</td>
			<?php else : ?>
				<td colspan="2">
					<div class="moksafowo-payuni-select-store-heading"><?php esc_html_e( '超商門市', 'moksa-for-woocommerce' ); ?></div>
					<div class="moksafowo-payuni-store-selector">
						<?php self::render_store_selector_content( $stored_store_data ); ?>
					</div>
				</td>
			<?php endif; ?>
		</tr>
		<?php
	}

	private static function render_store_selector_content( $stored_store_data ) {
		if ( empty( $stored_store_data ) ) {
			self::restore_store_data_from_post();
			$stored_store_data = WC()->session->get( 'moksafowo_payuni_selected_store_data', array() );
		}

		PayuniShipping::log( 'render_store_selector_content - stored_store_data: ' . wc_print_r( $stored_store_data, true ) );

		if ( ! empty( $stored_store_data ) && is_array( $stored_store_data ) ) :
			?>
			<div class="moksafowo-payuni-selected-store">
				<div class="store-info">
					<div class="store-name"><?php echo esc_html( $stored_store_data['name'] ?? '' ); ?></div>
					<div class="store-address"><?php echo esc_html( $stored_store_data['address'] ?? '' ); ?></div>
					<div class="store-meta">
						<?php /* translators: %s: convenience store ID */ ?>
						<span class="store-id"><?php echo esc_html( sprintf( __( '門市代號: %s', 'moksa-for-woocommerce' ), $stored_store_data['id'] ?? '' ) ); ?></span>
						<?php if ( isset( $stored_store_data['outside'] ) && ( $stored_store_data['outside'] === '1' || $stored_store_data['outside'] === 1 ) ) : ?>
							<span class="store-outside">⚠ <?php esc_html_e( '離島地區', 'moksa-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<button type="button" class="moksafowo-payuni-store-map-btn button"><?php esc_html_e( '更換門市', 'moksa-for-woocommerce' ); ?></button>
			</div>
		<?php else : ?>
			<div class="moksafowo-payuni-no-store">
				<p style="margin-bottom: 10px; color: #856404;"><?php esc_html_e( '尚未選擇取貨門市', 'moksa-for-woocommerce' ); ?></p>
				<button type="button" class="moksafowo-payuni-store-map-btn button"><?php esc_html_e( '選擇門市', 'moksa-for-woocommerce' ); ?></button>
			</div>
			<?php
		endif;
	}

	private static function restore_store_data_from_post() {
		if ( ! isset( $_POST['moksafowo_payuni_selected_store_id'], $_POST['moksafowo_payuni_selected_store_name'], $_POST['moksafowo_payuni_selected_store_address'] ) ) {
			return false;
		}

		// Verify the interstitial nonce or a WooCommerce native checkout nonce inline, before reading any field.
		$verified = false;
		foreach ( array(
			'moksafowo_payuni_store_nonce'       => 'moksafowo_payuni_restore_store',
			'woocommerce-process-checkout-nonce' => 'woocommerce-process_checkout',
			'security'                           => 'update-order-review',
		) as $field => $action ) {
			if ( isset( $_POST[ $field ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ), $action ) ) {
				$verified = true;
				break;
			}
		}
		if ( ! $verified ) {
			PayuniShipping::log( 'restore_store_data_from_post: nonce missing/invalid — ignored' );
			return false;
		}

		$store_id        = isset( $_POST['moksafowo_payuni_selected_store_id'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_payuni_selected_store_id'] ) ) : '';
		$store_name      = isset( $_POST['moksafowo_payuni_selected_store_name'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_payuni_selected_store_name'] ) ) : '';
		$store_address   = isset( $_POST['moksafowo_payuni_selected_store_address'] ) ? sanitize_text_field( wp_unslash( $_POST['moksafowo_payuni_selected_store_address'] ) ) : '';
		$store_data_json = isset( $_POST['moksafowo_payuni_selected_store_data'] ) ? sanitize_textarea_field( wp_unslash( $_POST['moksafowo_payuni_selected_store_data'] ) ) : '';

		$store_data = array(
			'id'      => $store_id,
			'name'    => $store_name,
			'address' => $store_address,
		);

		if ( ! empty( $store_data_json ) ) {
			$parsed_data = json_decode( $store_data_json, true );
			if ( json_last_error() === JSON_ERROR_NONE && $parsed_data ) {
				// json_decode 不做消毒 — 逐欄 sanitize 後才放進 session / 寫 meta
				$store_data = map_deep( $parsed_data, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
				PayuniShipping::log( 'Successfully parsed store JSON data from POST' );
			} else {
				PayuniShipping::log( 'JSON decode error from POST: ' . json_last_error_msg() );
			}
		}

		WC()->session->set( 'moksafowo_payuni_selected_store_data', $store_data );
		PayuniShipping::log( 'Store data restored from POST to WC Session: ' . wc_print_r( $store_data, true ) );
		return WC()->session->get( 'moksafowo_payuni_selected_store_data', array() );
	}

	public static function modify_billing_fields_for_cvs( $fields ) {
		if ( get_option( 'moksafowo_payuni_shipping_hide_billing_address_fields', 'no' ) !== 'yes' ) {
			return $fields;
		}

		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		if ( empty( $chosen_methods ) ) {
			return $fields;
		}

		$shipping_method = $chosen_methods[0];
		$method_id       = strpos( $shipping_method, ':' ) !== false ? explode( ':', $shipping_method )[0] : $shipping_method;

		if ( ! PayuniShipping::needs_cvs( $method_id ) ) {
			return $fields;
		}

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
				// 不用 'N/A' 佔位：returning customer 切回宅配時舊值會重浮
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
		if ( get_option( 'moksafowo_payuni_shipping_hide_billing_address_fields', 'no' ) !== 'yes' ) {
			return $data;
		}

		if ( empty( $data['shipping_method'] ) ) {
			return $data;
		}

		$shipping_method = is_array( $data['shipping_method'] ) ? $data['shipping_method'][0] : $data['shipping_method'];
		$method_id       = strpos( $shipping_method, ':' ) !== false ? explode( ':', $shipping_method )[0] : $shipping_method;

		if ( ! PayuniShipping::needs_cvs( $method_id ) ) {
			return $data;
		}

		// CVS 送門市 — home address 無需進訂單（含 returning customer autofill 情境）
		$fields_to_clear = array(
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_company',
			// shipping：保留 first_name / last_name / phone（門市取件需受件人）
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

		if ( empty( $data['billing_country'] ) || $data['billing_country'] === 'N/A' ) {
			$data['billing_country'] = WC()->countries->get_base_country();
		}

		PayuniShipping::log( 'Set default billing address for CVS shipping' );

		return $data;
	}
}
