<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Address;

defined( 'ABSPATH' ) || exit;

final class TwAddress {

	private static ?array $states_cache = null;

	private static ?array $cities_cache = null;

	public static function init(): void {
		// 欄位順序與寬度是進階設定裡獨立的一區，跟地址工具各自可以關。
		// init() 一定要跑（裡面有設定頁的欄位渲染），區塊開關在 FieldManager 內部判斷。
		FieldManager::init();

		// 欄位順序歸 TW_FIELD_LAYOUT 管，跟地址工具（TW_ADDRESS）是兩區，
		// 所以不能被 TW_ADDRESS 的早退擋掉 —— 只開排序的站台一樣要吃到前台 CSS。
		$layout_on = self::field_layout_on();

		// 區塊總開關關掉時，底下的子項一律不生效（見 AdvancedSections 的兩層開關備註）
		if ( ! \Moksafowo\Settings\AdvancedSections::is_on( \Moksafowo\Settings\AdvancedSections::TW_ADDRESS ) ) {
			if ( $layout_on ) {
				self::init_frontend_assets();
			}
			return;
		}

		if ( 'yes' === get_option( 'moksafowo_tw_address_dropdown_enabled', 'no' ) ) {
			self::init_dropdown();
		}
		if ( 'yes' === get_option( 'moksafowo_tw_address_name_swap', 'no' ) ) {
			self::init_name_swap();
		}
		if ( 'yes' === get_option( 'moksafowo_tw_address_hide_country', 'no' ) ) {
			self::init_hide_country();
		}
		if ( PhoneRule::enabled() ) {
			PhoneValidator::init();
		}

		// WC TW 預設 `{last_name} {first_name}` 在 Block 跑不出來（Block 只認 `{name}`）；
		// priority 100 跑在 PayuniShipping payuni_address_format(10) 之後再次 override。
		add_filter( 'woocommerce_localisation_address_formats', [ __CLASS__, 'tw_address_format_use_name_token' ], 100 );
		add_filter( 'woocommerce_formatted_address_replacements', [ __CLASS__, 'tw_name_token_last_first_order' ], 10, 2 );
		// priority 99：要跑在各家物流外掛（多半掛 10）之後，否則補好的縣市名又被蓋回代碼。
		add_filter( 'woocommerce_formatted_address_replacements', [ __CLASS__, 'repair_pseudo_country_state' ], 99, 2 );

		if ( self::any_toggle_on() || $layout_on ) {
			self::init_frontend_assets();
			add_filter( 'woocommerce_countries', [ __CLASS__, 'tw_first_in_dropdown' ] );
		}
	}

	/**
	 * 前台 CSS + body class。地址工具與欄位順序兩區都會用到，各自都可能是唯一開著的那個，
	 * 所以抽出來讓兩條路徑共用（重複 add_* 由 WP 自身去重，掛兩次也安全）。
	 */
	private static function init_frontend_assets(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_shared_css' ] );
		add_filter( 'body_class', [ __CLASS__, 'add_body_classes' ] );
	}

	public static function tw_address_format_use_name_token( array $formats ): array {
		if ( isset( $formats['TW'] ) ) {
			$formats['TW'] = "{postcode}\n{country} {state} {city}\n{address_1} {address_2}\n{company}\n{last_name} {first_name}\n";
		}
		return $formats;
	}

	/**
	 * 台灣縣市名補救 —— 假國別把 WC 的縣市查表打斷時用。
	 *
	 * 好幾個台灣物流外掛（含本外掛的 PayuniShipping，以及 WPBrewer 的 payuni-shipping）
	 * 會在 `woocommerce_order_formatted_shipping_address` 把 country 換成 `PNHD` /
	 * `PNCVS` 之類的假值，只為了讓 WC 套不同的地址版型。副作用是 WC 用
	 * `states[<country>][<state>]` 查縣市名時查不到，直接印出原始代碼 ——
	 * 運送地址變成「HSINCHU CITY」而帳單是「新竹市」。
	 *
	 * 這裡只在「國別不是 TW、但縣市值剛好對得上台灣縣市代碼」時補中文，
	 * 那組合只可能來自假國別，不會誤傷真的外國地址。
	 */
	public static function repair_pseudo_country_state( array $replacements, array $args ): array {
		$country = (string) ( $args['country'] ?? '' );
		$state   = (string) ( $args['state'] ?? '' );
		if ( '' === $state || 'TW' === $country ) {
			return $replacements;
		}
		$label = self::state_label( $state );
		if ( $label !== $state ) {
			$replacements['{state}'] = $label;
		}
		return $replacements;
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

	/**
	 * 欄位順序是進階設定裡「獨立的一區」，閘門必須跟 FieldManager::init() 一模一樣：
	 * 區塊開關 TW_FIELD_LAYOUT + 自身 toggle。以前這裡直接讀 toggle，於是
	 * 「區塊關掉但 toggle 還開著」時 Classic 不排、Block 照排，兩邊行為對不上。
	 */
	private static function field_layout_on(): bool {
		return \Moksafowo\Settings\AdvancedSections::is_on( \Moksafowo\Settings\AdvancedSections::TW_FIELD_LAYOUT )
			&& 'yes' === get_option( 'moksafowo_tw_address_reorder_fields', 'no' );
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
		$after              = ! empty( $args['clear'] ) ? '<div class="clear"></div>' : '';
		$required_indicator = '';
		if ( $args['required'] ) {
			$args['class'][]    = 'validate-required';
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
			$html .= '<option value="">' . esc_html__( 'Select…', 'moksa-for-woocommerce' ) . '</option>';

			$dropdown = [];
			if ( $current_sc && isset( $country_cities[ $current_sc ] ) ) {
				$dropdown = $country_cities[ $current_sc ];
			}

			foreach ( $dropdown as $city ) {
				$opt_attr = '';
				if ( is_array( $city ) ) {
					$opt_attr  = 'value="' . esc_attr( $city[0] ) . '" data-postcode="' . esc_attr( $city[1] ) . '"';
					$opt_attr .= selected( $value, $city[0], false );
					$label     = $city[0];
				} else {
					$opt_attr = 'value="' . esc_attr( $city ) . '"' . selected( $value, $city, false );
					$label    = $city;
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

	/**
	 * 「選好縣市區後自動帶入郵遞區號」設定。這個開關在設定頁存在很久，但兩支 JS 從來
	 * 沒讀它 —— 一直都是無條件帶入，勾不勾都一樣。現在真的接上；預設 yes 維持原本行為。
	 */
	public static function postcode_autofill_enabled(): bool {
		return 'yes' === get_option( 'moksafowo_tw_address_postcode_autofill', 'yes' );
	}

	public static function enqueue_dropdown_assets(): void {
		if ( ! is_cart() && ! is_checkout() && ! is_wc_endpoint_url( 'edit-address' ) ) {
			return;
		}

		$shared_data = [
			'cities'            => self::get_cities(),
			'postcode_autofill' => self::postcode_autofill_enabled(),
			'i18n'              => [
				'select_placeholder' => __( 'Select…', 'moksa-for-woocommerce' ),
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
		// Classic 結帳吃 priority；Block 結帳的順序改由 block_field_order_css() 的 CSS order 控制。
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

	/**
	 * Block 結帳欄位順序:WC Block 不讀 priority(它走 get_core_fields 的 index +
	 * locale priority,姓名等核心欄位無法可靠覆寫),故改用 CSS order 控制 —— Block 地址
	 * 表單為 flex,給每個欄位明確 order 即可照後台 layout 排。Classic 仍走 PHP priority。
	 */
	private static function block_field_order_css(): string {
		$scope = '.wp-block-woocommerce-checkout';
		$map   = [
			'first_name' => '.wc-block-components-address-form__first_name',
			'last_name'  => '.wc-block-components-address-form__last_name',
			'company'    => '.wc-block-components-address-form__company',
			'country'    => '.wc-block-components-address-form__country',
			'address_1'  => '.wc-block-components-address-form__address_1',
			'address_2'  => '.wc-block-components-address-form__address_2',
			'state'      => '.wc-block-components-address-form__state',
			'city'       => '.moksafowo-tw-district-wrapper',
			'postcode'   => '.wc-block-components-address-form__postcode',
			'phone'      => '.wc-block-components-address-form__phone',
		];

		// 啟用台式欄位順序 → 整份 layout 的順序 + 寬度套到 Block。
		// Block 地址表單為 flex-wrap、column-gap 12px,欄位預設 flex:1 0 calc(50% - 12px)。
		// 50% → 同一基準(兩兩配對並排、落單自動撐滿);100% → flex:0 0 100% 整列。
		if ( self::field_layout_on() ) {
			$css  = '';
			$idx  = 0;
			$used = [];
			foreach ( FieldManager::get_layout() as $item ) {
				$key = (string) ( $item['key'] ?? '' );
				if ( ! isset( $map[ $key ] ) ) {
					continue;
				}
				++$idx;
				$used[] = $map[ $key ];
				$flex   = 50 === (int) ( $item['width'] ?? 100 ) ? '1 0 calc(50% - 12px)' : '0 0 100%';
				$css   .= sprintf( '%s %s{order:%d;flex:%s !important}', $scope, $map[ $key ], $idx, $flex );
			}
			return $css . self::narrow_screen_flex_fallback( $scope, $used );
		}

		// 只開姓名對調 → 負值 order 把姓氏 / 名字 排到其他欄位之前(姓氏在前)。
		if ( 'yes' === get_option( 'moksafowo_tw_address_name_swap', 'no' ) ) {
			return sprintf(
				'%1$s %2$s{order:-2}%1$s %3$s{order:-1}',
				$scope,
				$map['last_name'],
				$map['first_name']
			) . self::narrow_screen_flex_fallback( $scope, [ $map['last_name'], $map['first_name'] ] );
		}

		return '';
	}

	/**
	 * 補上 WooCommerce 在 400px 以下漏掉的 flex 宣告。
	 *
	 * WC 用三段 media query 把區塊地址表單設成 flex —— 400~519、520~699、700+ ——
	 * **400px 以下沒有任何一段涵蓋**。那個寬度區間表單不是 flex 容器，而 `order`
	 * 只對 flex / grid 子元素有效，於是整份排序靜默失效，欄位照 DOM 原順序由上往下排。
	 * 多數手機是 360~390px，全部落在這個斷層裡 —— 「電腦順序對、手機不對」就是這樣來的。
	 *
	 * 補 flex 的同時把欄位一律拉成整列：WooCommerce 在這個寬度本來就是單欄，
	 * 在 360px 硬套 50% 會擠成兩欄，跟它的小螢幕設計相衝。商家設的 50% / 100%
	 * 仍然在 400px 以上照舊生效。
	 *
	 * @param string        $scope     區塊結帳的外層選擇器。
	 * @param array<string> $selectors 這次有排到序的欄位選擇器。
	 */
	private static function narrow_screen_flex_fallback( string $scope, array $selectors ): string {
		if ( empty( $selectors ) ) {
			return '';
		}
		$form = sprintf(
			'%1$s .wc-block-checkout__billing-fields .wc-block-components-address-form,%1$s .wc-block-checkout__shipping-fields .wc-block-components-address-form',
			$scope
		);
		$full = implode(
			',',
			array_map(
				static fn ( string $sel ): string => $scope . ' ' . $sel,
				array_unique( $selectors )
			)
		);
		return sprintf(
			'@media (max-width:399px){%s{display:flex;flex-wrap:wrap}%s{flex:0 0 100%% !important}}',
			$form,
			$full
		);
	}

	private static function init_hide_country(): void {
		add_filter( 'default_checkout_billing_country', [ __CLASS__, 'default_country_tw' ] );
		add_filter( 'default_checkout_shipping_country', [ __CLASS__, 'default_country_tw' ] );
		// 110：要跑在 tw_address_format_use_name_token(100) 之後。
		add_filter( 'woocommerce_localisation_address_formats', [ __CLASS__, 'strip_country_from_tw_format' ], 110 );
	}

	/**
	 * 區塊結帳的「地址摘要卡」是照 countryData[TW].format 組出來的單一文字節點
	 * （`100, 台灣 台北市, ...`），CSS 蓋得掉欄位、蓋不掉字串裡的一段，所以要從
	 * 格式本身把 {country} 拿掉。
	 *
	 * 只在結帳 / 購物車頁動手。地址格式是全站共用的，而且 WC_Countries 每個請求只算
	 * 一次就快取，所以這裡不能無條件改 —— 訂單信、後台訂單、匯出的地址都要保留國家。
	 * 區塊結帳送單走的是 Store API 的另一個請求（不是結帳頁），因此信件不受影響。
	 * 訂單完成頁也排除，那頁的地址視同訂單紀錄。
	 *
	 * @param array<string,string> $formats 各國地址格式。
	 * @return array<string,string>
	 */
	public static function strip_country_from_tw_format( array $formats ): array {
		if ( is_admin() || ! isset( $formats['TW'] ) ) {
			return $formats;
		}
		if ( ! function_exists( 'is_checkout' ) || ( ! is_checkout() && ! is_cart() ) ) {
			return $formats;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return $formats;
		}

		// 連同後面那個空格一起拿掉，避免留下 `  台北市` 這種雙空白。
		$formats['TW'] = str_replace( [ '{country} ', '{country}' ], '', $formats['TW'] );
		return $formats;
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

		if ( self::field_layout_on() ) {
			foreach ( FieldManager::get_layout() as $item ) {
				$key = sanitize_html_class( (string) $item['key'] );
				if ( empty( $item['enabled'] ) ) {
					$classes[] = 'moksafowo-tw-disable-' . $key;
					continue;
				}
				$width     = (int) ( $item['width'] ?? 100 );
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

		// Block 結帳欄位順序(依後台 layout)以 CSS order 注入。
		$order_css = self::block_field_order_css();
		if ( '' !== $order_css ) {
			wp_add_inline_style( 'moksafowo-tw-address', $order_css );
		}
	}

	public static function state_label( string $code ): string {
		if ( '' === $code ) {
			return '';
		}
		$map = self::get_states();
		return isset( $map[ $code ] ) ? (string) $map[ $code ] : $code;
	}

	/**
	 * 運送地址的多行元件,對齊帳單 TW 格式(郵遞 / 縣市區 / 地址),不含姓名與物流商。
	 *
	 * @param \WC_Order $order 訂單。
	 * @return string[]
	 */
	public static function shipping_address_lines( \WC_Order $order ): array {
		$lines    = array();
		$postcode = (string) $order->get_shipping_postcode();
		if ( '' !== $postcode ) {
			$lines[] = $postcode;
		}
		$city = (string) $order->get_shipping_city();
		if ( '' === $city ) {
			$city = (string) $order->get_meta( '_wc_shipping/moksafowo/district' );
		}
		$state_city = trim( self::state_label( (string) $order->get_shipping_state() ) . ' ' . $city );
		if ( '' !== $state_city ) {
			$lines[] = $state_city;
		}
		$street = trim( (string) $order->get_shipping_address_1() . ' ' . (string) $order->get_shipping_address_2() );
		if ( '' !== $street ) {
			$lines[] = $street;
		}
		return $lines;
	}

	public static function format_shipping_address( \WC_Order $order, bool $with_zip = true ): string {
		// 鄉鎮市區落地於 WC 標準 shipping_city；city 空才退用 block 結帳的 district 附加欄位。
		$district = (string) $order->get_shipping_city();
		if ( '' === $district ) {
			$district = (string) $order->get_meta( '_wc_shipping/moksafowo/district' );
		}
		$parts = array_filter(
			[
				self::state_label( (string) $order->get_shipping_state() ),
				$district,
				(string) $order->get_shipping_address_1(),
				(string) $order->get_shipping_address_2(),
			],
			static fn( string $v ): bool => '' !== $v
		);

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
			$file               = __DIR__ . '/Data/states-tw.php';
			$data               = is_file( $file ) ? include $file : [];
			self::$states_cache = $data['TW'] ?? [];
		}
		return self::$states_cache;
	}

	public static function get_cities( ?string $cc = null ) {
		if ( null === self::$cities_cache ) {
			$file               = __DIR__ . '/Data/cities-tw.php';
			$data               = is_file( $file ) ? include $file : [];
			self::$cities_cache = is_array( $data ) ? $data : [];
		}

		if ( null !== $cc ) {
			return self::$cities_cache[ $cc ] ?? false;
		}
		return self::$cities_cache;
	}
}
