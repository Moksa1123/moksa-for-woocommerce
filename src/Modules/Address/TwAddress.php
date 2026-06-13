<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Address;

defined( 'ABSPATH' ) || exit;

final class TwAddress {

	private static ?array $states_cache = null;

	private static ?array $cities_cache = null;

	public static function init(): void {
		if ( 'yes' === get_option( 'moksafowo_tw_address_dropdown_enabled', 'no' ) ) {
			self::init_dropdown();
		}
		if ( 'yes' === get_option( 'moksafowo_tw_address_name_swap', 'no' ) ) {
			self::init_name_swap();
		}
		if ( 'yes' === get_option( 'moksafowo_tw_address_hide_country', 'no' ) ) {
			self::init_hide_country();
		}

		FieldManager::init();

		// WC TW 預設 `{last_name} {first_name}` 在 Block 跑不出來（Block 只認 `{name}`）；
		// priority 100 跑在 PayuniShipping payuni_address_format(10) 之後再次 override。
		add_filter( 'woocommerce_localisation_address_formats', [ __CLASS__, 'tw_address_format_use_name_token' ], 100 );
		add_filter( 'woocommerce_formatted_address_replacements', [ __CLASS__, 'tw_name_token_last_first_order' ], 10, 2 );

		if ( self::any_toggle_on() ) {
			add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_shared_css' ] );
			add_filter( 'body_class', [ __CLASS__, 'add_body_classes' ] );
			add_filter( 'woocommerce_countries', [ __CLASS__, 'tw_first_in_dropdown' ] );
		}
	}

	public static function tw_address_format_use_name_token( array $formats ): array {
		if ( isset( $formats['TW'] ) ) {
			$formats['TW'] = "{postcode}\n{country} {state} {city}\n{address_1} {address_2}\n{company}\n{last_name} {first_name}\n";
		}
		return $formats;
	}

	public static function tw_name_token_last_first_order( array $replacements, array $args ): array {
		if ( ( $args['country'] ?? '' ) !== 'TW' ) {
			return $replacements;
		}
		$last  = (string) ( $args['last_name'] ?? '' );
		$first = (string) ( $args['first_name'] ?? '' );
		$name  = trim( $last . ' ' . $first );
		if ( '' !== $name ) {
			$replacements['{name}'] = $name;
		}
		return $replacements;
	}

	private static function any_toggle_on(): bool {
		foreach ( [
			'moksafowo_tw_address_dropdown_enabled',
			'moksafowo_tw_address_name_swap',
			'moksafowo_tw_address_hide_country',
		] as $opt ) {
			if ( 'yes' === get_option( $opt, 'no' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function init_dropdown(): void {
		add_filter( 'woocommerce_states', [ __CLASS__, 'load_states' ] );
		add_filter( 'woocommerce_billing_fields', [ __CLASS__, 'set_city_field_type' ] );
		add_filter( 'woocommerce_shipping_fields', [ __CLASS__, 'set_city_field_type' ] );
		// 最終 field array：強制 city 非必填，讓條件式驗證（validate_district_for_home）單一裁定。
		add_filter( 'woocommerce_checkout_fields', [ __CLASS__, 'relax_checkout_city_required' ], 100 );
		add_filter( 'woocommerce_form_field_city', [ __CLASS__, 'render_city_field' ], 10, 4 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_dropdown_assets' ] );

		BlockField::init();
	}

	public static function load_states( array $states ): array {
		$tw = self::get_states();
		if ( ! empty( $tw ) ) {
			$states = array_merge( $states, [ 'TW' => $tw ] );
		}
		return $states;
	}

	public static function relax_checkout_city_required( array $fields ): array {
		foreach ( [ 'billing', 'shipping' ] as $group ) {
			if ( isset( $fields[ $group ][ $group . '_city' ] ) ) {
				$fields[ $group ][ $group . '_city' ]['required'] = false;
			}
		}
		return $fields;
	}

	public static function set_city_field_type( array $fields ): array {
		foreach ( [ 'billing_city', 'shipping_city' ] as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$fields[ $key ]['type'] = 'city';
				// 鄉鎮市區改條件式必填（僅宅配）— 解除 WC core 對 city 的全域必填，
				// 改由 BlockField::validate_district_for_home() 依物流類型驗證（CVS 免填）。
				$fields[ $key ]['required'] = false;
			}
		}
		return $fields;
	}

	public static function render_city_field( string $field, string $key, array $args, $value ): string {
		$after = ! empty( $args['clear'] ) ? '<div class="clear"></div>' : '';
		$required_indicator = '';
		if ( $args['required'] ) {
			$args['class'][] = 'validate-required';
			$required_indicator = '&nbsp;<span class="required" aria-hidden="true">*</span>';
		}
		if ( ! empty( $args['validate'] ) ) {
			foreach ( $args['validate'] as $v ) {
				$args['class'][] = 'validate-' . $v;
			}
		}

		$custom_attrs = [];
		if ( ! empty( $args['custom_attributes'] ) && is_array( $args['custom_attributes'] ) ) {
			foreach ( $args['custom_attributes'] as $a => $v ) {
				$custom_attrs[] = esc_attr( $a ) . '="' . esc_attr( $v ) . '"';
			}
		}

		$html = '<p class="form-row ' . esc_attr( implode( ' ', $args['class'] ) ) . '" id="' . esc_attr( $args['id'] ) . '_field">';
		if ( $args['label'] ) {
			$html .= '<label for="' . esc_attr( $args['id'] ) . '" class="' . esc_attr( implode( ' ', $args['label_class'] ) ) . '">' . wp_kses_post( $args['label'] ) . $required_indicator . '</label>';
		}

		$country_key = 'billing_city' === $key ? 'billing_country' : 'shipping_country';
		$state_key   = 'billing_city' === $key ? 'billing_state' : 'shipping_state';
		$current_cc  = WC()->checkout->get_value( $country_key );
		$current_sc  = WC()->checkout->get_value( $state_key );

		$country_cities = self::get_cities( $current_cc );

		$html .= '<span class="woocommerce-input-wrapper">';
		if ( is_array( $country_cities ) ) {
			$html .= '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" class="city_select ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attrs ) . ' placeholder="' . esc_attr( $args['placeholder'] ) . '">';
			$html .= '<option value="">' . esc_html__( '請選擇…', 'mo-ectools' ) . '</option>';

			$dropdown = [];
			if ( $current_sc && isset( $country_cities[ $current_sc ] ) ) {
				$dropdown = $country_cities[ $current_sc ];
			}

			foreach ( $dropdown as $city ) {
				$opt_attr = '';
				if ( is_array( $city ) ) {
					$opt_attr = 'value="' . esc_attr( $city[0] ) . '" data-postcode="' . esc_attr( $city[1] ) . '"';
					$opt_attr .= selected( $value, $city[0], false );
					$label = $city[0];
				} else {
					$opt_attr = 'value="' . esc_attr( $city ) . '"' . selected( $value, $city, false );
					$label = $city;
				}
				$html .= '<option ' . $opt_attr . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select>';
		} else {
			$html .= '<input type="text" class="input-text ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" ' . implode( ' ', $custom_attrs ) . ' />';
		}

		if ( $args['description'] ) {
			$html .= '<span class="description">' . esc_attr( $args['description'] ) . '</span>';
		}
		$html .= '</span></p>' . $after;

		return $html;
	}

	public static function enqueue_dropdown_assets(): void {
		if ( ! is_cart() && ! is_checkout() && ! is_wc_endpoint_url( 'edit-address' ) ) {
			return;
		}

		$shared_data = [
			'cities' => self::get_cities(),
			'i18n'   => [
				'select_placeholder' => __( '請選擇…', 'mo-ectools' ),
			],
		];

		wp_enqueue_script(
			'moksafowo-tw-address',
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/Address/assets/js/moksafowo-tw-address.js',
			[ 'jquery', 'woocommerce' ],
			MOKSAFOWO_VERSION,
			true
		);
		wp_localize_script( 'moksafowo-tw-address', 'moksafowo_tw_address', $shared_data );
	}

	private static function init_name_swap(): void {
		add_filter( 'woocommerce_default_address_fields', [ __CLASS__, 'swap_name_priorities' ] );
	}

	public static function swap_name_priorities( array $fields ): array {
		if ( isset( $fields['last_name'] ) ) {
			$fields['last_name']['priority'] = 5;
		}
		if ( isset( $fields['first_name'] ) ) {
			$fields['first_name']['priority'] = 15;
		}
		return $fields;
	}

	private static function init_hide_country(): void {
		add_filter( 'default_checkout_billing_country', [ __CLASS__, 'default_country_tw' ] );
		add_filter( 'default_checkout_shipping_country', [ __CLASS__, 'default_country_tw' ] );
	}

	public static function default_country_tw( $country ) {
		return $country ?: 'TW';
	}

	public static function tw_first_in_dropdown( array $countries ): array {
		if ( is_admin() ) {
			return $countries;
		}
		if ( ! isset( $countries['TW'] ) ) {
			return $countries;
		}
		$tw = $countries['TW'];
		unset( $countries['TW'] );
		return [ 'TW' => $tw ] + $countries;
	}

	public static function add_body_classes( array $classes ): array {
		if ( 'yes' === get_option( 'moksafowo_tw_address_hide_country', 'no' ) ) {
			$classes[] = 'moksafowo-tw-hide-country';
		}
		if ( 'yes' === get_option( 'moksafowo_tw_address_name_swap', 'no' ) ) {
			$classes[] = 'moksafowo-tw-name-swap';
		}

		if ( 'yes' === get_option( 'moksafowo_tw_address_reorder_fields', 'no' ) ) {
			foreach ( FieldManager::get_layout() as $item ) {
				$key = sanitize_html_class( (string) $item['key'] );
				if ( empty( $item['enabled'] ) ) {
					$classes[] = 'moksafowo-tw-disable-' . $key;
					continue;
				}
				$width = (int) ( $item['width'] ?? 100 );
				$classes[] = 'moksafowo-tw-w-' . $key . '-' . ( 50 === $width ? 50 : 100 );
			}
		}

		return $classes;
	}

	public static function enqueue_shared_css(): void {
		if ( ! is_cart() && ! is_checkout() && ! is_wc_endpoint_url( 'edit-address' ) ) {
			return;
		}
		$path    = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Address/assets/css/moksafowo-tw-address.css';
		$version = file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOWO_VERSION;
		wp_enqueue_style(
			'moksafowo-tw-address',
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/Address/assets/css/moksafowo-tw-address.css',
			[],
			$version
		);
	}

	public static function state_label( string $code ): string {
		if ( '' === $code ) {
			return '';
		}
		$map = self::get_states();
		return isset( $map[ $code ] ) ? (string) $map[ $code ] : $code;
	}

	public static function format_shipping_address( \WC_Order $order, bool $with_zip = true ): string {
		$parts = array_filter( [
			self::state_label( (string) $order->get_shipping_state() ),
			(string) $order->get_meta( '_shipping_mowp/district' ),
			(string) $order->get_shipping_city(),
			(string) $order->get_shipping_address_1(),
			(string) $order->get_shipping_address_2(),
		], static fn( string $v ): bool => '' !== $v );

		if ( $with_zip ) {
			$zip = (string) $order->get_shipping_postcode();
			if ( '' !== $zip ) {
				array_unshift( $parts, $zip );
			}
		}
		return implode( ' ', $parts );
	}

	private static function get_states(): array {
		if ( null === self::$states_cache ) {
			$file = __DIR__ . '/Data/states-tw.php';
			$data = is_file( $file ) ? include $file : [];
			self::$states_cache = $data['TW'] ?? [];
		}
		return self::$states_cache;
	}

	public static function get_cities( ?string $cc = null ) {
		if ( null === self::$cities_cache ) {
			$file = __DIR__ . '/Data/cities-tw.php';
			$data = is_file( $file ) ? include $file : [];
			self::$cities_cache = is_array( $data ) ? $data : [];
		}

		if ( null !== $cc ) {
			return self::$cities_cache[ $cc ] ?? false;
		}
		return self::$cities_cache;
	}
}
