<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping;

use Automattic\Jetpack\Constants;
use MoksaWeb\Mowc\Modules\Payuni\Credentials;
use MoksaWeb\Mowc\Modules\PayuniShipping\Api\Crypto;
use MoksaWeb\Mowc\Modules\PayuniShipping\Frontend\AddressFormatter;
use MoksaWeb\Mowc\Modules\PayuniShipping\Frontend\EnqueueScripts;
use MoksaWeb\Mowc\Modules\PayuniShipping\Frontend\CheckoutFields;
use MoksaWeb\Mowc\Modules\PayuniShipping\Frontend\StoreValidation;
use MoksaWeb\Mowc\Modules\PayuniShipping\Operations\SaveShippingMeta;
use MoksaWeb\Mowc\Modules\PayuniShipping\Settings\SettingsTab;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\MethodIdPredicates;
use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\SevenEleven\B2CFrozen;
use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\SevenEleven\B2CNormal;
use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\SevenEleven\C2CFrozen;
use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\SevenEleven\C2CNormal;
use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\SevenEleven\B2CUnified as Seven711B2CUnified;
use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\SevenEleven\C2CUnified as Seven711C2CUnified;
use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\TCat\HDFrozen;
use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\TCat\HDNormal;
use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\TCat\HDRefrigerated;
use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\TCat\HDUnified;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\SingletonTrait;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShipType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\GoodsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\LgsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ServiceType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Admin\OrderEdit;
use MoksaWeb\Mowc\Modules\PayuniShipping\Api\ShippingRequest;
use MoksaWeb\Mowc\Modules\PayuniShipping\Api\ShippingResponse;
use MoksaWeb\Mowc\Modules\PayuniShipping\Frontend\StoreSelector;



defined( 'ABSPATH' ) || exit;


class PayuniShipping {

	use SingletonTrait;


	public static $log_enabled = false;


	public static $log = false;


	public static $mer_id;


	public static $hashkey;


	public static $hashiv;


	public static $testmode;


	public static $api_url;


	public static $shipping_status_url;



	public static $order_status_ready_shipping;


	public static $order_status_at_logistic_center;


	public static $order_status_at_sender_cvs;


	public static $order_status_delivering;


	public static $order_status_at_receiver_cvs;


	public static $order_status_pickuped;

	private static $tcat_delivery_time;


	public static $cvs_selector_layout;


	protected static $js_data;


	public static $cvs_methods = array(
		C2CNormal::ID          => C2CNormal::class,
		C2CFrozen::ID          => C2CFrozen::class,
		B2CNormal::ID          => B2CNormal::class,
		B2CFrozen::ID          => B2CFrozen::class,
		Seven711C2CUnified::ID => Seven711C2CUnified::class,
		Seven711B2CUnified::ID => Seven711B2CUnified::class,
	);


	public static $hd_methods = array(
		HDNormal::ID       => HDNormal::class,
		HDFrozen::ID       => HDFrozen::class,
		HDRefrigerated::ID => HDRefrigerated::class,
		HDUnified::ID      => HDUnified::class,
	);


	public static function init() {

		self::get_instance();

		self::$mer_id  = Credentials::merchant_id();
		self::$hashkey = Credentials::hashkey();
		self::$hashiv  = Credentials::hashiv();

		self::$log_enabled = wc_string_to_bool( get_option( 'moksafowo_payuni_shipping_debug_log_enabled', 'no' ) );
		self::$testmode    = wc_string_to_bool( get_option( 'moksafowo_payuni_shipping_testmode_enabled' ) );
		self::$api_url     = ( self::$testmode ) ? 'https://sandbox-api.payuni.com.tw/api' : 'https://api.payuni.com.tw/api';

		self::$shipping_status_url = add_query_arg( 'wc-api', 'shipping_status_callback', home_url( '/' ) );

		self::$order_status_ready_shipping     = get_option( 'moksafowo_payuni_shipping_order_status_ready_shipping' );
		self::$order_status_at_logistic_center = get_option( 'moksafowo_payuni_shipping_order_status_at_logistic_center' );
		self::$order_status_at_sender_cvs      = get_option( 'moksafowo_payuni_shipping_order_status_at_sender_cvs' );
		self::$order_status_delivering         = get_option( 'moksafowo_payuni_shipping_order_status_delivering' );
		self::$order_status_at_receiver_cvs    = get_option( 'moksafowo_payuni_shipping_order_status_at_receiver_cvs' );
		self::$order_status_pickuped           = get_option( 'moksafowo_payuni_shipping_order_status_pickuped' );

		self::$tcat_delivery_time  = get_option( 'moksafowo_payuni_shipping_tcat_delivery_time', '04' );
		self::$cvs_selector_layout = get_option( 'moksafowo_payuni_shipping_cvs_selector_layout', 'two_column' );

		add_filter( 'woocommerce_shipping_methods', array( self::get_instance(), 'moksafowo_payuni_add_shipping_methods' ) );
		add_filter( 'woocommerce_checkout_fields', array( self::get_instance(), 'moksafowo_payuni_shpping_cvs_field' ), 9999 );
		add_filter( 'woocommerce_update_order_review_fragments', array( self::get_instance(), 'shipping_choose_cvs_info' ) );
		add_action( 'woocommerce_checkout_process', array( self::get_instance(), 'moksafowo_payuni_shipping_fields_validation' ) );

		// Block checkout 不 fire woocommerce_checkout_process — throw RouteException 擋下未選門市的下單
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'block_validate_cvs_store' ), 10, 2 );

		add_action( 'woocommerce_checkout_create_order', array( self::get_instance(), 'moksafowo_payuni_save_order_hd_shipping_meta' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( self::get_instance(), 'moksafowo_payuni_checkout_enqueue_scripts' ), 9 );
		add_filter( 'woocommerce_order_formatted_shipping_address', array( self::get_instance(), 'moksafowo_payuni_raw_shipping_address' ), 10, 2 );
		add_filter( 'woocommerce_localisation_address_formats', array( self::get_instance(), 'moksafowo_payuni_address_format' ) );
		add_filter( 'woocommerce_formatted_address_replacements', array( self::get_instance(), 'moksafowo_payuni_shipping_address_replacements' ), 10, 2 );
		add_filter( 'woocommerce_shipping_address_map_url_parts', array( self::get_instance(), 'moksafowo_payuni_shipping_address_map' ), 10, 2 );

		Frontend\CustomerOrderView::init();

		add_action( 'admin_enqueue_scripts', array( self::get_instance(), 'moksafowo_payuni_enqueue_admin_script' ) );
		add_action( 'woocommerce_saved_order_items', array( self::get_instance(), 'moksafowo_payuni_saved_order_items' ), 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( self::get_instance(), 'set_default_payment_gateway_over_20000' ) );

		ShippingRequest::init();
		ShippingResponse::init();
		Webhook\StatusMapper::init();
		OrderEdit::init();
		StoreSelector::init();
		Admin\OrderListHelper::init();
		Operations\PrintProxy::init();

		add_filter( 'moksafowo_shipping_batch_print_providers', [ __CLASS__, 'register_batch_print' ] );
		Emails\EmailTrackingProvider::init();
	}

	public static function register_batch_print( array $providers ): array {
		$cvs_titles = [
			'moksafowo_payuni_shipping_711_c2c'        => __( 'PAYUNi 7-11 C2C 取貨', 'mo-ectools' ),
			'moksafowo_payuni_shipping_711_b2c'        => __( 'PAYUNi 7-11 B2C 取貨', 'mo-ectools' ),
			'moksafowo_payuni_shipping_711_c2c_normal' => __( 'PAYUNi 7-11 店到店常溫', 'mo-ectools' ),
			'moksafowo_payuni_shipping_711_c2c_frozen' => __( 'PAYUNi 7-11 店到店冷凍', 'mo-ectools' ),
			'moksafowo_payuni_shipping_711_b2c_normal' => __( 'PAYUNi 7-11 大宗常溫', 'mo-ectools' ),
			'moksafowo_payuni_shipping_711_b2c_frozen' => __( 'PAYUNi 7-11 大宗冷凍', 'mo-ectools' ),
		];
		$hd_titles  = [
			'moksafowo_payuni_shipping_tcat'              => __( 'PAYUNi 黑貓宅配', 'mo-ectools' ),
			'moksafowo_payuni_shipping_tcat_normal'       => __( 'PAYUNi 黑貓常溫', 'mo-ectools' ),
			'moksafowo_payuni_shipping_tcat_frozen'       => __( 'PAYUNi 黑貓冷凍', 'mo-ectools' ),
			'moksafowo_payuni_shipping_tcat_refrigerated' => __( 'PAYUNi 黑貓冷藏', 'mo-ectools' ),
		];
		$cvs        = array_intersect_key( $cvs_titles, self::$cvs_methods );
		$hd         = array_intersect_key( $hd_titles, self::$hd_methods );

		// unified TCat：多溫層拆單走 records list；legacy 走 single ShipTradeNo
		$counter  = static function ( \WC_Order $o ): int {
			$records = Operations\CreateOrderUnified::get_records( $o );
			if ( ! empty( $records ) ) {
				return count( $records );
			}
			return '' !== (string) $o->get_meta( Utils\OrderMeta::ShipTradeNo ) ? 1 : 0;
		};
		$temps_fn = static function ( \WC_Order $o ): array {
			$out = [];
			foreach ( Operations\CreateOrderUnified::get_records( $o ) as $r ) {
				$t = (int) ( $r['temp'] ?? 0 );
				if ( $t > 0 ) {
					$out[ $t ] = true;
				}
			}
			return array_keys( $out );
		};

		if ( ! empty( $cvs ) ) {
			$providers['moksafowo-payuni-cvs'] = [
				'label'           => __( 'PAYUNi 超商標籤', 'mo-ectools' ),
				'category'        => 'cvs',
				'method_ids'      => $cvs,
				'handler'         => [ Operations\BatchPrint::class, 'cvs' ],
				'record_counter'  => $counter,
				'record_temps'    => $temps_fn,
				// LabelMode: 1=A4 / 2=直立式 A6（僅 B2C 適用）；C2C 只能 A4
				'paper_modes'     => [ '1', '2' ],
				'row_paper_modes' => static function ( \WC_Order $o ): array {
					$lgs_type = (string) $o->get_meta( '_moksafowo_payuni_shipping_lgs_type' );
					return 'B2C' === $lgs_type ? [ '1', '2' ] : [ '1' ];
				},
			];
		}
		if ( ! empty( $hd ) ) {
			$providers['moksafowo-payuni-home'] = [
				'label'          => __( 'PAYUNi 宅配標籤', 'mo-ectools' ),
				'category'       => 'home',
				'method_ids'     => $hd,
				'handler'        => [ Operations\BatchPrint::class, 'home' ],
				'record_counter' => $counter,
				'record_temps'   => $temps_fn,
				'paper_modes'    => [ '1' ], // TCAT PrintType 固定 1，API 不接受 paper size
			];
		}
		return $providers;
	}


	public function moksafowo_payuni_add_shipping_methods( $methods ) {
		$methods[ C2CNormal::ID ]          = C2CNormal::class;
		$methods[ C2CFrozen::ID ]          = C2CFrozen::class;
		$methods[ B2CNormal::ID ]          = B2CNormal::class;
		$methods[ B2CFrozen::ID ]          = B2CFrozen::class;
		$methods[ HDNormal::ID ]           = HDNormal::class;
		$methods[ HDFrozen::ID ]           = HDFrozen::class;
		$methods[ HDRefrigerated::ID ]     = HDRefrigerated::class;
		$methods[ Seven711C2CUnified::ID ] = Seven711C2CUnified::class;
		$methods[ Seven711B2CUnified::ID ] = Seven711B2CUnified::class;
		$methods[ HDUnified::ID ]          = HDUnified::class;
		return $methods;
	}


	public function moksafowo_payuni_add_shipping_settings( $settings ) {
		if ( is_array( $settings ) ) {
			$settings[] = new SettingsTab();
		} else {
			$other_settings = $settings;
			$settings       = array( new SettingsTab(), $other_settings );
		}

		return $settings;
	}


	public function set_default_payment_gateway_over_20000( $available_gateways ) {
		if ( ! is_admin() && is_checkout() && WC()->cart ) {
			$cart_total          = WC()->cart->total;
			$default_pro_gateway = 'moksafowo-payuni-pro-credit';
			$default_upp_gateway = 'moksafowo-payuni-upp-credit';

			if ( $cart_total > 20000 ) {
				if ( isset( $available_gateways[ $default_pro_gateway ] ) ) {
					// Set the chosen payment method to the default gateway
					WC()->session->set( 'chosen_payment_method', $default_pro_gateway );
				} elseif ( isset( $available_gateways[ $default_upp_gateway ] ) ) {
					WC()->session->set( 'chosen_payment_method', $default_upp_gateway );
				}
			}
		}
		return $available_gateways;
	}



	public static function moksafowo_payuni_shpping_cvs_field( $fields ) {
		$chosen_shipping_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();
		$is_payuni_cvs           = false;
		$is_payuni_hd            = false;

		if ( ! empty( $chosen_shipping_methods ) ) {
			$chosen_method = $chosen_shipping_methods[0];
			$method_id     = strpos( $chosen_method, ':' ) !== false ? explode( ':', $chosen_method )[0] : $chosen_method;

			if ( self::is_moksafowo_payuni_shipping_cvs( $method_id ) ) {
				$is_payuni_cvs = true;
			} elseif ( self::is_moksafowo_payuni_shipping_hd( $method_id ) ) {
				$is_payuni_hd = true;
			}
		}

		if ( ( $is_payuni_cvs || $is_payuni_hd ) && ! isset( $fields['shipping']['shipping_phone'] ) ) {
			$fields['shipping']['shipping_phone'] = array(
				'label'    => __( 'Shipping Phone', 'mo-ectools' ),
				'required' => true,
				'type'     => 'tel',
				'validate' => array( 'phone' ),
				'class'    => array( 'form-row-wide', 'moksafowo-payuni-shipping-field' ),
				'priority' => 100,
			);
		}

		if ( is_checkout() && $is_payuni_cvs ) {
			$cvs_hide_fields = array(
				'shipping_country',
				'shipping_postcode',
				'shipping_state',
				'shipping_city',
				'shipping_address_1',
				'shipping_address_2',
				'shipping_company',
			);

			foreach ( $cvs_hide_fields as $field_key ) {
				if ( isset( $fields['shipping'][ $field_key ] ) ) {
					if ( ! isset( $fields['shipping'][ $field_key ]['class'] ) ) {
						$fields['shipping'][ $field_key ]['class'] = array();
					} elseif ( ! is_array( $fields['shipping'][ $field_key ]['class'] ) ) {
						$fields['shipping'][ $field_key ]['class'] = array( $fields['shipping'][ $field_key ]['class'] );
					}
					$fields['shipping'][ $field_key ]['class'][] = 'moksafowo-payuni-cvs-hide';
				}
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		return apply_filters( 'moksafowo_payuni_shipping_cvs_fields', $fields );
	}


	public static function moksafowo_payuni_setup_shipping_info() {
		self::$js_data = array();

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_method_id        = strstr( $chosen_shipping_methods[0], ':', true );
		self::log( 'moksafowo_payuni_setup_shipping_info, chosen_method_id:' . $chosen_method_id );
		if ( ! self::is_moksafowo_payuni_shipping_cvs( $chosen_method_id ) ) {
			self::log( 'moksafowo_payuni_setup_shipping_info, chosen_method_id is not payuni 7-11 cvs, return' );
			return;
		}

		$encrypt_info = array(
			'MerID'        => Credentials::merchant_id(),
			'Timestamp'    => time(),
			'MerKeyNo'     => '1234567890',
			'GoodsType'    => '1', // 1常溫, 2冷凍
			'LgsType'      => LgsType::get_lgs_type_by_shipping_method( $chosen_method_id ),
			'ShipType'     => '1', // 1=Seven
			'MapType'      => '2',
			'MapReturnURL' => esc_url( WC()->api_request_url( 'moksafowo_payuni_choose_cvs_callback' ) . '?cid=' . WC()->cart->get_cart_hash() ),
			'Tag'          => '2',
			'MobileTag'    => wp_is_mobile() ? 'Y' : 'N',
		);

		self::log( 'payuni setup shipping info, request encrypt info:' . wc_print_r( $encrypt_info, true ) );

		self::$js_data['shipping_data']['methods']  = $chosen_method_id;
		self::$js_data['shipping_data']['ShipType'] = ShipType::get_ship_type( $chosen_method_id );

		$encrypted_info = self::encrypt( $encrypt_info );

		if ( self::is_moksafowo_payuni_shipping_cvs( $chosen_method_id ) ) {
			self::$js_data['shipping_data']['source']        = 'shipping_choose_cvs';
			self::$js_data['shipping_data']['is_payuni_cvs'] = true;
			self::$js_data['shipping_data']['MerID']         = Credentials::merchant_id();
			self::$js_data['shipping_data']['Version']       = '1.1';
			self::$js_data['shipping_data']['EncryptInfo']   = $encrypted_info;
			self::$js_data['shipping_data']['HashInfo']      = self::hash_info( $encrypted_info );
			self::$js_data['shipping_data']['ajax_url']      = self::$api_url . '/logistics/ship_map';
		}
	}


	public static function shipping_choose_cvs_info( $fragments ) {

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_method_id        = strstr( $chosen_shipping_methods[0], ':', true );

		if ( ! self::is_moksafowo_payuni_shipping_cvs( $chosen_method_id ) ) {
			return $fragments;
		}

		if ( ! empty( self::$js_data ) ) {

			$ship_type               = ShipType::get_ship_type( $chosen_method_id );
			$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
			$chosen_method_id        = strstr( $chosen_shipping_methods[0], ':', true );
			self::log( 'chosen_method_id:' . $chosen_method_id );

			if ( self::is_moksafowo_payuni_shipping_cvs( $chosen_method_id ) ) {
				self::$js_data['shipping_data']['ShipType'] = $ship_type;
				if ( array_key_exists( 'methods', self::$js_data['shipping_data'] ) ) {
					self::$js_data['shipping_data']['methods'] = $chosen_method_id;
				}

				$store_data = WC()->session->get( 'moksafowo_payuni_selected_store_data' );
				if ( ! empty( $store_data ) && is_array( $store_data ) ) {
					self::$js_data['shipping_data']['store_info']    = array(
						'store_id'      => $store_data['id'] ?? '',
						'store_name'    => $store_data['name'] ?? '',
						'store_address' => $store_data['address'] ?? '',
					);
					self::$js_data['shipping_data']['is_payuni_cvs'] = true;
				} else {
					self::$js_data['shipping_data']['store_info']    = null;
					self::$js_data['shipping_data']['is_payuni_cvs'] = true;
				}
			} elseif ( self::is_moksafowo_payuni_shipping_hd( $chosen_method_id ) ) {
				self::$js_data['shipping_data']                  = array();
				self::$js_data['shipping_data']['ShipType']      = $ship_type;
				self::$js_data['shipping_data']['methods']       = $chosen_method_id;
				self::$js_data['shipping_data']['is_payuni_cvs'] = false;
			} else {
				self::$js_data['shipping_data']                  = array();
				self::$js_data['shipping_data']['is_payuni_cvs'] = false;
			}

			$fragments['moksafowo_payuni_shipping_info'] = apply_filters( 'moksafowo_payuni_setup_cvs_data', self::$js_data, $chosen_shipping_methods );

		}

		return $fragments;
	}



	public static function block_validate_cvs_store( \WC_Order $order, $request ): void {
		StoreValidation::block_validate_cvs_store( $order, $request );
	}

	public static function moksafowo_payuni_shipping_fields_validation() {
		StoreValidation::classic_fields_validation();
	}

	public static function setup_family_frozen_shipping_fields_requirements( $fields ) {
		return CheckoutFields::setup_family_frozen_shipping_fields_requirements( $fields );
	}

	public static function setup_cvs_shipping_fields_requirements( $fields ) {
		return CheckoutFields::setup_cvs_shipping_fields_requirements( $fields );
	}

	public static function setup_hd_shipping_fields_requirements( $fields ) {
		return CheckoutFields::setup_hd_shipping_fields_requirements( $fields );
	}

	public static function remove_shipping_phone_required( $fields ) {
		return CheckoutFields::remove_shipping_phone_required( $fields );
	}


	public static function moksafowo_payuni_save_order_hd_shipping_meta( $order, $data ) {
		SaveShippingMeta::save_hd_shipping_meta( $order, $data );
	}

	public static function moksafowo_payuni_checkout_enqueue_scripts() {
		EnqueueScripts::checkout();
	}

	public static function moksafowo_payuni_enqueue_admin_script() {
		EnqueueScripts::admin();
	}


	public static function moksafowo_payuni_raw_shipping_address( $raw_address, $order ) {
		return AddressFormatter::raw_shipping_address( $raw_address, $order );
	}

	public static function moksafowo_payuni_address_format( $address_formats ) {
		return AddressFormatter::address_format( $address_formats );
	}

	public static function moksafowo_payuni_shipping_address_replacements( $replacements, $args ) {
		return AddressFormatter::address_replacements( $replacements, $args );
	}

	public static function moksafowo_payuni_shipping_address_map( $address, $order ) {
		return AddressFormatter::address_map( $address, $order );
	}


	public static function save_shipping_info( $order, $data ) {
		SaveShippingMeta::save_ship_trade_no( $order, $data );
	}

	public static function moksafowo_payuni_saved_order_items( $order_id, $items ) {
		SaveShippingMeta::on_saved_order_items( $order_id, $items );
	}

	public static function get_order_total_weight( $order ) {
		return SaveShippingMeta::get_order_total_weight( $order );
	}


	public static function needs_cvs( $method_id ) {
		return MethodIdPredicates::needs_cvs( $method_id );
	}

	public static function is_moksafowo_payuni_shipping_cvs( $shipping_method_id ) {
		return MethodIdPredicates::is_moksafowo_payuni_shipping_cvs( $shipping_method_id );
	}

	public static function is_payuni_shipping( $shipping_method_id ) {
		return MethodIdPredicates::is_payuni_shipping( $shipping_method_id );
	}

	public static function is_moksafowo_payuni_shipping_hd( $shipping_method_id ) {
		return MethodIdPredicates::is_moksafowo_payuni_shipping_hd( $shipping_method_id );
	}

	public static function is_payuni_payment( $payment_method ) {
		return MethodIdPredicates::is_payuni_payment( $payment_method );
	}


	public static function moksafowo_payuni_get_shipping_phone( $order ) {
		if ( version_compare( Constants::get_constant( 'WC_VERSION' ), '5.6.0', '>=' ) ) {
			return $order->get_shipping_phone();
		} else {
			return $order->get_meta( '_shipping_phone' );
		}
	}


	public function update_checkout_on_payment_method_change() {
		// no-op
	}

	public static function format_cvs_shipno( $order ) {
		return AddressFormatter::format_cvs_shipno( $order );
	}


	public static function credentials(): array {
		return [ Credentials::hashkey(), Credentials::hashiv() ];
	}

	public static function encrypt( $encrypt_info ) {
		return Crypto::encrypt( is_array( $encrypt_info ) ? $encrypt_info : [] );
	}

	public static function decrypt( string $encrypt_str = '' ) {
		return Crypto::decrypt( $encrypt_str );
	}

	public static function hash_info( string $encrypt_str = '' ) {
		return Crypto::hash_info( $encrypt_str );
	}


	public function moksafowo_payuni_add_action_links( $links ) {
		$setting_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=payuni&section=shipping' ) . '">' . __( 'General Settings', 'mo-ectools' ) . '</a>',
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping' ) . '">' . __( 'Shipping Settings', 'mo-ectools' ) . '</a>',
		);
		return array_merge( $links, $setting_links );
	}


	public static function log( $message, $level = 'info' ) {
		if ( ! self::$log_enabled ) {
			return;
		}
		$msg_str = is_string( $message ) ? $message : (string) wp_json_encode( $message );
		$method  = in_array( $level, [ 'info', 'warning', 'error', 'debug' ], true ) ? $level : 'info';
		\MoksaWeb\Mowc\Logging\Logger::{$method}( 'moksafowo-payuni-shipping', $msg_str );

		$redacted = \MoksaWeb\Mowc\Logging\Redactor::redact_string( $msg_str );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- belt-and-suspenders fallback when WC log dir not writable; mowp Logger does not propagate redacted result back.
		error_log( '[mo-ectools-payuni-shipping][' . $method . '] ' . $redacted );
	}


	public static function get_mer_id() {
		return self::$mer_id;
	}


	public static function get_tcat_delivery_time() {
		return self::$tcat_delivery_time;
	}
}
