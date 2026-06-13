<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Address;

defined( 'ABSPATH' ) || exit;

final class BlockField {

	private const FIELD_ID = 'mowp/district';

	public static function init(): void {
		if ( 'yes' !== get_option( 'moksafowo_tw_address_dropdown_enabled', 'no' ) ) {
			return;
		}
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		add_action( 'woocommerce_set_additional_field_value', [ __CLASS__, 'sync_to_core_city' ], 10, 4 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_block_assets' ] );
		add_filter( 'body_class', [ __CLASS__, 'add_body_class' ] );
		add_filter( 'woocommerce_get_country_locale', [ __CLASS__, 'make_tw_city_optional' ], 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'fill_city_from_district' ], 10, 2 );

		// 條件式必填：宅配（HOME）才要鄉鎮市區，CVS 取貨送門市不需。
		// Classic 走 after_checkout_validation；Block(Store API) 該 hook 不擋，改在
		// update_order_from_request（priority 20，跑在 fill_city_from_district 之後）丟 RouteException。
		add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'validate_district_for_home' ], 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'enforce_district_block' ], 20, 2 );

		// 鄉鎮市區已同步進 city（地址行顯示用），WC 又會把 address-location 額外欄位
		// 渲染成獨立一行「鄉鎮市區：信義區」造成重複。訂單建立後清掉額外欄位儲存值
		// （city 保留），WC 顯示時跳過空值 → 消除重複行。客戶儲存地址不受影響。
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'strip_district_additional_meta' ] );

		// Lazy register — 只在 Store API request 或 frontend cart/checkout/edit-address 頁攤平 370 鄉鎮。
		add_action( 'rest_api_init', [ __CLASS__, 'maybe_register_field' ] );
		add_action( 'wp', [ __CLASS__, 'maybe_register_field' ] );
	}

	public static function maybe_register_field(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}

		if ( doing_action( 'rest_api_init' ) ) {
			$route = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			if ( false === strpos( $route, '/wc/store/' ) ) {
				return;
			}
		} elseif ( ! is_checkout() && ! is_cart() && ! is_wc_endpoint_url( 'edit-address' ) ) {
			return;
		}

		self::register_field();
		$registered = true;
	}

	public static function make_tw_city_optional( array $locale ): array {
		if ( ! isset( $locale['TW'] ) || ! is_array( $locale['TW'] ) ) {
			$locale['TW'] = [];
		}
		if ( ! isset( $locale['TW']['city'] ) || ! is_array( $locale['TW']['city'] ) ) {
			$locale['TW']['city'] = [];
		}
		$locale['TW']['city']['required'] = false;
		$locale['TW']['city']['hidden']   = true;
		return $locale;
	}

	public static function fill_city_from_district( \WC_Order $order, $request ): void {
		$additional = $request->get_param( 'extensions' ) ?? [];
		$billing  = (array) ( $request->get_param( 'billing_address' ) ?? [] );
		$shipping = (array) ( $request->get_param( 'shipping_address' ) ?? [] );

		$pick = static function ( $arr ): string {
			if ( ! is_array( $arr ) ) {
				return '';
			}
			$v = $arr[ self::FIELD_ID ] ?? ( $arr['mowp/district'] ?? '' );
			if ( ! is_string( $v ) || '' === $v ) {
				return '';
			}
			return explode( '|', $v, 2 )[0];
		};

		$shipping_city = $pick( $shipping );
		$billing_city  = $pick( $billing );

		if ( '' !== $shipping_city && '' === $order->get_shipping_city() ) {
			$order->set_shipping_city( $shipping_city );
		}
		if ( '' !== $billing_city && '' === $order->get_billing_city() ) {
			$order->set_billing_city( $billing_city );
		}
	}

	
	public static function strip_district_additional_meta( \WC_Order $order ): void {
		$changed = false;
		foreach ( [ '_wc_billing/mowp/district', '_wc_shipping/mowp/district' ] as $key ) {
			if ( '' !== (string) $order->get_meta( $key ) ) {
				$order->delete_meta_data( $key );
				$changed = true;
			}
		}
		if ( $changed ) {
			$order->save();
		}
	}

	
	public static function validate_district_for_home( $data, $errors ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}
		// CVS 取貨 → 不需鄉鎮市區。
		if ( self::chosen_shipping_is_cvs() ) {
			return;
		}
		$district = self::posted_district( is_array( $data ) ? $data : [] );
		if ( '' === $district ) {
			$errors->add( 'mowp_district_required', __( '宅配訂單請選擇鄉鎮市區。', 'mo-ectools' ) );
		}
	}

	
	public static function enforce_district_block( \WC_Order $order, $request ): void {
		$methods = ( function_exists( 'WC' ) && WC()->shipping() ) ? WC()->shipping()->get_shipping_methods() : [];
		foreach ( $order->get_shipping_methods() as $sm ) {
			$inst = $methods[ $sm->get_method_id() ] ?? null;
			if ( $inst instanceof \MoksaWeb\Mowc\Modules\Shipping\Methods\AbstractCvsShippingMethod ) {
				return; // CVS 取貨 → 免填
			}
		}
		if ( '' === $order->get_shipping_city() && '' === $order->get_billing_city() ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'mowp_district_required',
				esc_html__( '宅配訂單請選擇鄉鎮市區。', 'mo-ectools' ),
				400
			);
		}
	}

	private static function chosen_shipping_is_cvs(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}
		$chosen  = (array) WC()->session->get( 'chosen_shipping_methods', [] );
		$methods = ( WC()->shipping() ) ? WC()->shipping()->get_shipping_methods() : [];
		foreach ( $chosen as $entry ) {
			$id = ( false !== strpos( (string) $entry, ':' ) ) ? strstr( (string) $entry, ':', true ) : (string) $entry;
			$m  = $methods[ $id ] ?? null;
			if ( $m instanceof \MoksaWeb\Mowc\Modules\Shipping\Methods\AbstractCvsShippingMethod ) {
				return true;
			}
		}
		return false;
	}

	private static function posted_district( array $data ): string {
		foreach ( [ 'shipping_city', 'billing_city' ] as $k ) {
			if ( ! empty( $data[ $k ] ) ) {
				return (string) $data[ $k ];
			}
		}
		// Block Store API：additional_fields[mowp/district]
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 讀值供驗證，下單流程自有 nonce
		if ( isset( $_POST['additional_fields'] ) && is_array( $_POST['additional_fields'] ) ) {
			$v = isset( $_POST['additional_fields'][ self::FIELD_ID ] ) ? sanitize_text_field( wp_unslash( $_POST['additional_fields'][ self::FIELD_ID ] ) ) : '';
			if ( '' !== $v ) {
				return explode( '|', $v, 2 )[0];
			}
		}
		// Block 下單時 fill_city_from_district 已把值寫進 customer/order city — 再撈一次 session customer。
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$c = (string) WC()->customer->get_shipping_city();
			if ( '' !== $c ) {
				return $c;
			}
			$b = (string) WC()->customer->get_billing_city();
			if ( '' !== $b ) {
				return $b;
			}
		}
		// phpcs:enable
		return '';
	}

	public static function register_field(): void {
		$cities = TwAddress::get_cities( 'TW' );
		if ( ! is_array( $cities ) ) {
			return;
		}

		// API 不支援動態 options，攤平全 370 鄉鎮一次列入；同名（如「東區」）加 |state 後綴。
		$options = [];
		$seen    = [];
		foreach ( $cities as $state_key => $city_list ) {
			foreach ( $city_list as $city ) {
				$name = is_array( $city ) ? (string) $city[0] : (string) $city;
				if ( isset( $seen[ $name ] ) ) {
					$value = $name . '|' . $state_key;
				} else {
					$value         = $name;
					$seen[ $name ] = true;
				}
				$options[] = [
					'value' => $value,
					'label' => $name,
				];
			}
		}

		woocommerce_register_additional_checkout_field( [
			'id'          => self::FIELD_ID,
			'label'       => __( '鄉鎮市區', 'mo-ectools' ),
			'location'    => 'address',
			'type'        => 'select',
			// 不全域 required — CVS 取貨送門市不需鄉鎮市區；只有宅配（HOME）才要，
			// 由 validate_district_for_home() 條件式驗證。
			'required'    => false,
			'placeholder' => __( '請選擇…', 'mo-ectools' ),
			'options'     => $options,
			// additional checkout fields 沒有 priority option，順序與寬度走 CSS order property。
		] );
	}

	public static function sync_to_core_city( string $key, $value, string $group, $object ): void {
		if ( self::FIELD_ID !== $key ) {
			return;
		}

		$city_value = is_string( $value ) ? explode( '|', $value, 2 )[0] : '';
		if ( '' === $city_value ) {
			return;
		}

		if ( $object instanceof \WC_Customer ) {
			if ( 'billing' === $group ) {
				$object->set_billing_city( $city_value );
			} elseif ( 'shipping' === $group ) {
				$object->set_shipping_city( $city_value );
			}
		} elseif ( $object instanceof \WC_Order ) {
			if ( 'billing' === $group ) {
				$object->set_billing_city( $city_value );
			} elseif ( 'shipping' === $group ) {
				$object->set_shipping_city( $city_value );
			}
		}
	}

	public static function enqueue_block_assets(): void {
		if ( ! is_cart() && ! is_checkout() && ! is_wc_endpoint_url( 'edit-address' ) ) {
			return;
		}

		wp_enqueue_script(
			'moksafowo-tw-district-block',
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/Address/assets/js/moksafowo-tw-district-block.js',
			[],
			MOKSAFOWO_VERSION,
			true
		);

		$cities         = TwAddress::get_cities( 'TW' );
		$state_to_cities = [];
		$postcode_map    = [];
		$seen            = [];
		if ( is_array( $cities ) ) {
			foreach ( $cities as $state_key => $city_list ) {
				$values = [];
				foreach ( $city_list as $city ) {
					$name = is_array( $city ) ? (string) $city[0] : (string) $city;
					if ( isset( $seen[ $name ] ) && $seen[ $name ] !== $state_key ) {
						$value = $name . '|' . $state_key;
					} else {
						$value = $name;
						$seen[ $name ] = $state_key;
					}
					$values[] = $value;
					if ( is_array( $city ) ) {
						$postcode_map[ $value ] = (string) $city[1];
					}
				}
				$state_to_cities[ $state_key ] = $values;
			}
		}

		wp_localize_script( 'moksafowo-tw-district-block', 'moksafowo_tw_district', [
			'field_id'   => self::FIELD_ID,
			'by_state'   => $state_to_cities,
			'postcodes'  => $postcode_map,
			'placeholder' => __( '請選擇…', 'mo-ectools' ),
		] );
	}

	public static function add_body_class( array $classes ): array {
		$classes[] = 'moksafowo-tw-block-district-enabled';
		return $classes;
	}
}
