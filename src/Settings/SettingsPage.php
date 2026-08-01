<?php
declare( strict_types=1 );

namespace Moksafowo\Settings;

use Moksafowo\Plugin;
use Moksafowo\Modules\OrderLookup\Index\Backfill;
use Moksafowo\Modules\OrderLookup\Index\Table;

defined( 'ABSPATH' ) || exit;

final class SettingsPage extends \WC_Settings_Page {

	/**
	 * 不在「模組總覽」放卡片的模組 —— 它們的開關在別的分頁。
	 *
	 * ⚠️ 渲染與儲存必須用同一份清單。只在渲染端排除、儲存端照樣迭代，
	 * 會讓沒有卡片的模組每次儲存都被設成 'no'（沒有 POST 值就當作沒勾）。
	 */
	private const OVERVIEW_EXCLUDED = [ 'order_lookup' ];

	public function __construct() {
		$this->id    = SettingsTab::TAB_ID;
		$this->label = __( 'Moksa E-Commerce Tools', 'moksa-for-woocommerce' );
		parent::__construct();

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		// 自訂 settings field：訂單查號索引狀態 + 重建鈕（乾淨的 <tr>，不破版）。
		add_action( 'woocommerce_admin_field_moksafowo_order_index_status', [ $this, 'render_index_status' ] );
	}

	/**
	 * 自訂 field 渲染：索引啟用時輸出一列「狀態 + 重建索引」。
	 */
	public function render_index_status(): void {
		$html = self::order_index_status_html();
		if ( '' === $html ) {
			return;
		}
		echo '<tr valign="top"><th scope="row" class="titledesc">'
			. esc_html__( 'Index status', 'moksa-for-woocommerce' )
			. '</th><td class="forminp">'
			. wp_kses_post( $html )
			. '</td></tr>';
	}

	/**
	 * 訂單查號索引的狀態 + 重建連結（HTML，含已逸出的 button 連結）。
	 */
	private static function order_index_status_html(): string {
		if ( 'yes' !== get_option( Table::ENABLED_OPTION, 'no' ) ) {
			return '';
		}
		$status = Backfill::status();
		if ( $status['running'] ) {
			$msg = sprintf(
				/* translators: 1: processed count, 2: total count */
				__( 'Building the index… %1$d / %2$d orders processed (refresh to see progress).', 'moksa-for-woocommerce' ),
				$status['done'],
				max( $status['total'], $status['done'] )
			);
		} else {
			$rows = Table::exists() ? Table::count_orders() : 0;
			/* translators: %d: indexed order count */
			$msg = sprintf( __( '%d orders indexed.', 'moksa-for-woocommerce' ), $rows );
		}
		$url = wp_nonce_url(
			add_query_arg( 'moksafowo_rebuild_order_index', '1' ),
			'moksafowo_rebuild_order_index'
		);
		return esc_html( $msg ) . sprintf(
			' <a href="%s" class="button button-secondary">%s</a>',
			esc_url( $url ),
			esc_html__( 'Rebuild index', 'moksa-for-woocommerce' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- WordPress options.php submit; nonce '_wpnonce' verified by WP core.
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( SettingsTab::TAB_ID !== $tab ) {
			return;
		}
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		// 全 Moksa tab 共用視覺優化 — section 包成 card + 可收合（CSS/JS 走 enqueue）
		$css_path = MOKSAFOWO_PLUGIN_DIR . 'assets/admin/settings-polish.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : MOKSAFOWO_VERSION;
		wp_enqueue_style( 'moksafowo-settings-polish', MOKSAFOWO_PLUGIN_URL . 'assets/admin/settings-polish.css', [], $css_ver );
		$shell_path = MOKSAFOWO_PLUGIN_DIR . 'assets/admin/settings-shell.css';
		$shell_ver  = file_exists( $shell_path ) ? (string) filemtime( $shell_path ) : MOKSAFOWO_VERSION;
		wp_enqueue_style( 'moksafowo-settings-shell', MOKSAFOWO_PLUGIN_URL . 'assets/admin/settings-shell.css', [], $shell_ver );
		$js_path = MOKSAFOWO_PLUGIN_DIR . 'assets/admin/settings-polish.js';
		$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MOKSAFOWO_VERSION;
		wp_enqueue_script( 'moksafowo-settings-polish', MOKSAFOWO_PLUGIN_URL . 'assets/admin/settings-polish.js', [], $js_ver, true );

		// 只在 ECPay 三組子 section + PAYUNi shipping 載入
		if ( ! in_array( $section, [ 'ecpay', 'ecpay-shipping', 'ecpay-invoice', 'moksafowo-payuni-shipping' ], true ) ) {
			return;
		}
		// 用 filemtime 當版號，admin JS 改動每次自動 cache-bust，不靠 plugin VERSION bump。
		$path    = MOKSAFOWO_PLUGIN_DIR . 'assets/admin/ecpay-sandbox-toggle.js';
		$version = file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOWO_VERSION;
		wp_enqueue_script(
			'moksafowo-ecpay-sandbox-toggle',
			MOKSAFOWO_PLUGIN_URL . 'assets/admin/ecpay-sandbox-toggle.js',
			[],
			$version,
			true
		);
	}

	private static function module_descriptors(): array {
		return [
			'ecpay'                     => [
				'enable_key'  => 'ecpay',
				'label'       => __( 'ECPay payments', 'moksa-for-woocommerce' ),
				'banner_name' => __( 'ECPay payments', 'moksa-for-woocommerce' ),
				'tab_class'   => 'Moksafowo\\Modules\\Ecpay\\Settings\\SettingsTab',
				'tab_method'  => 'get_settings',
				'tab_arg'     => null,
			],
			'ecpay-shipping'            => [
				'enable_key'  => 'ecpay_shipping',
				'label'       => __( 'ECPay shipping', 'moksa-for-woocommerce' ),
				'banner_name' => __( 'ECPay shipping', 'moksa-for-woocommerce' ),
				'tab_class'   => 'Moksafowo\\Modules\\EcpayShipping\\Settings\\SettingsTab',
				'tab_method'  => 'get_settings',
				'tab_arg'     => null,
			],
			'ecpay-invoice'             => [
				'enable_key'  => 'ecpay_invoice',
				'label'       => __( 'ECPay e-invoice', 'moksa-for-woocommerce' ),
				'banner_name' => __( 'ECPay e-invoice', 'moksa-for-woocommerce' ),
				'tab_class'   => 'Moksafowo\\Modules\\EcpayInvoice\\Settings\\SettingsTab',
				'tab_method'  => 'get_settings',
				'tab_arg'     => null,
			],
			'newebpay'                  => [
				'enable_key' => 'newebpay',
				'label'      => __( 'NewebPay payments', 'moksa-for-woocommerce' ),
				'tab_class'  => 'Moksafowo\\Modules\\Newebpay\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'newebpay-shipping'         => [
				'enable_key' => 'newebpay_shipping',
				'label'      => __( 'NewebPay shipping', 'moksa-for-woocommerce' ),
				'tab_class'  => 'Moksafowo\\Modules\\NewebpayShipping\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'ezpay-invoice'             => [
				'enable_key' => 'ezpay_invoice',
				'label'      => __( 'ezPay e-invoice', 'moksa-for-woocommerce' ),
				'tab_class'  => 'Moksafowo\\Modules\\EzpayInvoice\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'smilepay-invoice'          => [
				'enable_key' => 'smilepay_invoice',
				'label'      => __( 'SmilePay e-invoice', 'moksa-for-woocommerce' ),
				'tab_class'  => 'Moksafowo\\Modules\\SmilepayInvoice\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'paynow-invoice'            => [
				'enable_key' => 'paynow_invoice',
				'label'      => __( 'PayNow e-invoice', 'moksa-for-woocommerce' ),
				'tab_class'  => 'Moksafowo\\Modules\\PaynowInvoice\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'amego-invoice'             => [
				'enable_key' => 'amego_invoice',
				'label'      => __( 'Amego e-invoice', 'moksa-for-woocommerce' ),
				'tab_class'  => 'Moksafowo\\Modules\\AmegoInvoice\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'linepay'                   => [
				'enable_key'  => 'linepay',
				'label'       => __( 'LINE Pay', 'moksa-for-woocommerce' ),
				'banner_name' => __( 'LINE Pay Taiwan', 'moksa-for-woocommerce' ),
				'tab_class'   => 'Moksafowo\\Modules\\Linepay\\Settings\\SettingsTab',
				'tab_method'  => 'get_settings',
				'tab_arg'     => '',
			],
			'moksafowo-payuni-payment'  => [
				'enable_key'  => 'payuni',
				'label'       => __( 'PAYUNi payments', 'moksa-for-woocommerce' ),
				'banner_name' => __( 'PAYUNi', 'moksa-for-woocommerce' ),
				'tab_class'   => 'Moksafowo\\Modules\\Payuni\\Settings\\SettingsTab',
				'tab_method'  => 'get_settings_for_payment_section',
				'tab_arg'     => null,
			],
			'moksafowo-payuni-shipping' => [
				'enable_key'  => 'payuni_shipping',
				'label'       => __( 'PAYUNi shipping', 'moksa-for-woocommerce' ),
				'banner_name' => __( 'PAYUNi shipping', 'moksa-for-woocommerce' ),
				'tab_class'   => 'Moksafowo\\Modules\\PayuniShipping\\Settings\\SettingsTab',
				'tab_method'  => 'get_settings_for_shipping_section',
				'tab_arg'     => null,
			],
			'smilepay-shipping'         => [
				'enable_key' => 'smilepay_shipping',
				'label'      => __( 'SmilePay shipping', 'moksa-for-woocommerce' ),
				'tab_class'  => 'Moksafowo\\Modules\\SmilepayShipping\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'smilepay-payment'          => [
				'enable_key' => 'smilepay',
				'label'      => __( 'SmilePay payments', 'moksa-for-woocommerce' ),
				'tab_class'  => 'Moksafowo\\Modules\\Smilepay\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'pchomepay'                 => [
				'enable_key' => 'pchomepay',
				'label'      => __( 'PChomePay', 'moksa-for-woocommerce' ),
				'tab_class'  => 'Moksafowo\\Modules\\Pchomepay\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'tappay'                    => [
				'enable_key' => 'tappay',
				'label'      => __( 'TapPay', 'moksa-for-woocommerce' ),
				'tab_class'  => 'Moksafowo\\Modules\\Tappay\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'paynow'                    => [
				'enable_key' => 'paynow',
				'label'      => __( 'PayNow', 'moksa-for-woocommerce' ),
				'tab_class'  => 'Moksafowo\\Modules\\Paynow\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'shopline-payments'         => [
				'enable_key' => 'shopline_payments',
				'label'      => __( 'Shopline Payments', 'moksa-for-woocommerce' ),
				'tab_class'  => 'Moksafowo\\Modules\\ShoplinePayments\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
		];
	}

	public function get_sections(): array {
		$sections = [
			''         => __( 'Overview', 'moksa-for-woocommerce' ),
			'advanced' => __( 'Advanced settings', 'moksa-for-woocommerce' ),
		];
		foreach ( self::module_descriptors() as $section => $desc ) {
			if ( 'yes' === get_option( 'moksafowo_' . $desc['enable_key'] . '_enabled', 'no' ) ) {
				$sections[ $section ] = $desc['label'];
			}
		}
		// 本類完全覆寫 WC_Settings_Page::get_sections()(未呼叫 parent::),WC 核心
		// 本身只用回傳陣列渲染分頁、不要求特定 filter tag 名稱 —— 走自家前綴。
		return apply_filters( 'moksafowo_get_sections_' . $this->id, $sections );
	}

	public function get_settings( $current_section = '' ): array {
		if ( 'advanced' === $current_section ) {
			return $this->advanced_fields();
		}
		$descriptors = self::module_descriptors();
		if ( ! isset( $descriptors[ $current_section ] ) ) {
			return [];
		}
		$desc = $descriptors[ $current_section ];
		if ( isset( $desc['require_file'] ) && file_exists( $desc['require_file'] ) ) {
			require_once $desc['require_file'];
		}
		return self::proxy_get_settings( $desc['tab_class'], $desc['tab_method'], $desc['tab_arg'] );
	}

	private static function proxy_get_settings( string $class, string $method, ?string $arg ): array {
		if ( ! class_exists( $class ) ) {
			return [];
		}
		try {
			$ref    = new \ReflectionClass( $class );
			$obj    = $ref->newInstanceWithoutConstructor();
			$result = $arg === null ? $obj->{$method}() : $obj->{$method}( $arg );
			return is_array( $result ) ? $result : [];
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	public function output(): void {
		global $current_section;
		$section = (string) $current_section;

		if ( '' === $section ) {
			$this->render_general_section();
			return;
		}

		Ui::open_shell();
		$this->render_subsection_banner( $section );
		\WC_Admin_Settings::output_fields( $this->get_settings( $section ) );
		Ui::close_shell();
	}

	public function save(): void {
		global $current_section;
		$section = (string) $current_section;

		if ( '' === $section ) {
			$this->save_general_section();
			return;
		}

		\WC_Admin_Settings::save_fields( $this->get_settings( $section ) );

		// WC_Settings_Page::save() normally fires this so modules can persist
		// custom field types; this class overrides save() so we fire it here.
		do_action( 'woocommerce_update_options_' . $this->id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC core convention extension point.
	}

	private function advanced_fields(): array {
		return [
			// 物流共用
			[
				'title' => __( 'Shared shipping settings', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Behavior settings shared by every shipping module.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shipping_common_section',
			],
			[
				'title'         => __( 'Bulk printing interface', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_shipping_bulk_print_mode_basic',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'desc'          => __( 'Basic — use the built-in WooCommerce bulk actions dropdown: tick the orders, then pick the bulk action.', 'moksa-for-woocommerce' ),
				'checkboxgroup' => 'start',
				'class'         => 'moksafowo-bulk-print-mode',
			],
			[
				'id'            => 'moksafowo_shipping_bulk_print_mode_advanced',
				'type'          => 'checkbox',
				'default'       => 'no',
				'desc'          => __( 'Advanced — the “ECPay store / home delivery labels” toolbar button opens a dialog that hides already-printed orders, so you can tick them and print in one go without missing any.', 'moksa-for-woocommerce' ),
				'checkboxgroup' => 'end',
				'class'         => 'moksafowo-bulk-print-mode',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shipping_common_section',
			],

			// 自訂訂單狀態（可選擇性停用避免 status dropdown 過長）
			[
				'title' => __( 'Custom order statuses', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Custom order statuses shared by the shipping modules. Once disabled they no longer appear on the order edit screen or in the bulk actions menu, but existing orders keep the status they already have.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shipping_status_section',
			],
			[
				'title'         => __( 'Shipped', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_shipping_status_moksafowo_shipped_enabled',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'desc'          => __( 'The carrier has collected the parcel and delivery is under way', 'moksa-for-woocommerce' ),
				'checkboxgroup' => 'start',
			],
			[
				'id'            => 'moksafowo_shipping_status_moksafowo_cvs_arrived_enabled',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'desc'          => __( 'Arrived at the store and waiting for pickup (convenience store pickup only)', 'moksa-for-woocommerce' ),
				'checkboxgroup' => '',
			],
			[
				'id'            => 'moksafowo_shipping_status_moksafowo_store_closed_enabled',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'desc'          => __( 'Store closed (the convenience store has closed and another store must be chosen)', 'moksa-for-woocommerce' ),
				'checkboxgroup' => 'end',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shipping_status_section',
			],

			// 訂單狀態 badge 顏色 — 緊湊 color grid（custom field type）
			[
				'title' => __( 'Order status colors', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Background and text colors for the status labels in the WordPress order list. Click a swatch to pick a color with the built-in WordPress color picker; the preview on the right updates as you go.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shipping_status_color_section',
			],
			[
				'type' => 'moksafowo_status_color_grid',
				'id'   => 'moksafowo_status_color_grid',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shipping_status_color_section',
			],

			// 台灣地址工具
			[
				'title' => __( 'Taiwan address tools', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Taiwan localization for the checkout address fields. Works with both classic and block checkout.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_tw_address_section',
			],
			[
				'title'         => __( 'Enable Taiwan city and district dropdowns', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_tw_address_dropdown_enabled',
				'type'          => 'checkbox',
				'default'       => 'no',
				'desc'          => __( 'Turn the city and district address fields into dropdowns so customers cannot mistype them.', 'moksa-for-woocommerce' ),
				'checkboxgroup' => 'start',
			],
			[
				'id'            => 'moksafowo_tw_address_postcode_autofill',
				'type'          => 'checkbox',
				'desc'          => __( 'Fill in the postcode automatically once the city and district are chosen, using the latest official postcode table', 'moksa-for-woocommerce' ),
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'id'            => 'moksafowo_tw_address_name_swap',
				'type'          => 'checkbox',
				'desc'          => __( 'Swap the name fields — change the WooCommerce default “First name / Last name” order to the Taiwanese “Last name / First name”.', 'moksa-for-woocommerce' ),
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'id'            => 'moksafowo_tw_address_hide_country',
				'type'          => 'checkbox',
				'desc'          => __( 'Hide the “Country / Region” field (for stores that only ship within Taiwan)', 'moksa-for-woocommerce' ),
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'id'            => 'moksafowo_tw_address_reorder_fields',
				'type'          => 'checkbox',
				'desc'          => __( 'Enable the Taiwanese field order and widths (drag to arrange below) — the order and the 50% / 100% widths apply to both classic and block checkout.', 'moksa-for-woocommerce' ),
				'default'       => 'no',
				'checkboxgroup' => 'end',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_tw_address_section',
			],

			// 台灣欄位順序與寬度（field manager UI）
			[
				'title' => __( 'Taiwan field order and widths', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Drag to reorder the fields and pick a 50% or 100% width. “Enable the Taiwanese field order” above must be ticked for this to reach the checkout page.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_tw_field_manager_section',
			],
			[
				'title' => __( 'Field order and width', 'moksa-for-woocommerce' ),
				'type'  => 'moksafowo_field_manager',
				'desc'  => __( 'Half-width fields sit side by side only in pairs (for example Last name 50% + First name 50% on one row). A 50% field left on its own falls back to 100%. Applies to both classic and block checkout.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_tw_address_field_layout',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_tw_field_manager_section',
			],

			// 訂單查號搜尋
			[
				'title' => __( 'Order number lookup', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Let the WooCommerce order search box and the Ctrl+K command palette recognize Taiwan-specific numbers. Only numbers from enabled modules are searched.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_order_lookup_section',
			],
			[
				'title'   => __( 'Enable order number lookup', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_order_lookup_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Once enabled you can look up orders by the numbers below from the order list search box and the command palette.', 'moksa-for-woocommerce' ),
			],
			[
				'title'         => __( 'Number types to search', 'moksa-for-woocommerce' ),
				'desc'          => __( 'Invoice number', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_order_lookup_field_invoice',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			],
			[
				'desc'          => __( 'Tracking number', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_order_lookup_field_shipping',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'Payment transaction ID', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_order_lookup_field_payment',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'Tax ID', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_order_lookup_field_ubn',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'ATM virtual account', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_order_lookup_field_atm',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'Convenience store payment code', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_order_lookup_field_cvs',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'Last four card digits', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_order_lookup_field_card',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'T-Cat tracking number', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_order_lookup_field_tcat',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => 'end',
				'desc_tip'      => __( 'Tick the number types to include in the search. The more fields, the slower the search — tick only the ones you actually use.', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( 'Speed up with an index (recommended for stores with many orders)', 'moksa-for-woocommerce' ),
				'id'      => Table::ENABLED_OPTION,
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Store the searchable numbers in a dedicated index table so lookups stay fast as the store grows. The index is built in the background once enabled.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'moksafowo_order_index_status',
				'id'   => 'moksafowo_order_lookup_index_status',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_order_lookup_section',
			],

		];
	}

	private function render_general_section(): void {
		$registry = Plugin::instance()->modules();

		$by_category = [
			'payment'  => [],
			'shipping' => [],
			'invoice'  => [],
			'checkout' => [],
			'tools'    => [],
		];
		foreach ( $registry->all() as $key => $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			if ( in_array( $key, self::OVERVIEW_EXCLUDED, true ) ) {
				continue;
			}
			$instance = new $class();
			$category = $instance->category();
			if ( ! isset( $by_category[ $category ] ) ) {
				$by_category[ $category ] = [];
			}
			$by_category[ $category ][ $key ] = $instance;
		}

		$category_labels = [
			'payment'  => __( 'Payment modules', 'moksa-for-woocommerce' ),
			'shipping' => __( 'Shipping modules', 'moksa-for-woocommerce' ),
			'invoice'  => __( 'E-invoice modules', 'moksa-for-woocommerce' ),
			'checkout' => __( 'Checkout tools', 'moksa-for-woocommerce' ),
			'tools'    => __( 'Tools', 'moksa-for-woocommerce' ),
		];

		$tab = SettingsTab::TAB_ID;

		Ui::open_shell();
		Ui::intro(
			__( 'Moksa — Taiwan toolkit', 'moksa-for-woocommerce' ),
			__( 'Enable the modules you need. Payment, shipping and e-invoicing can be mixed and matched freely.', 'moksa-for-woocommerce' )
		);

		foreach ( $by_category as $category => $entries ) {
			if ( $entries === [] ) {
				continue;
			}
			$cards = [];
			foreach ( $entries as $key => $module ) {
				$option       = sprintf( 'moksafowo_%s_enabled', $key );
				$enabled      = 'yes' === get_option( $option, 'no' );
				$section_slug = $module->settings_section();
				$cards[]      = [
					'name'         => $module->name(),
					'tagline'      => $module->tagline(),
					'methods'      => $module->methods(),
					'enabled'      => $enabled,
					'toggle_name'  => $option,
					'settings_url' => $section_slug !== ''
						? admin_url( sprintf( 'admin.php?page=wc-settings&tab=%s&section=%s', $tab, $section_slug ) )
						: '',
				];
			}
			Ui::category_group( $category_labels[ $category ] ?? ucfirst( $category ), $cards );
		}

		Ui::close_shell();
	}

	private function render_subsection_banner( string $section ): void {
		$descriptors = self::module_descriptors();
		if ( ! isset( $descriptors[ $section ] ) || ! isset( $descriptors[ $section ]['banner_name'] ) ) {
			return;
		}
		$desc = $descriptors[ $section ];

		$tab_url = admin_url( 'admin.php?page=wc-settings&tab=' . SettingsTab::TAB_ID );
		$enabled = 'yes' === get_option( 'moksafowo_' . $desc['enable_key'] . '_enabled', 'no' );

		Ui::subsection_banner( $desc['banner_name'], $enabled, $tab_url );

		if ( ! $enabled ) {
			?>
			<div class="notice notice-warning inline" style="margin: 0 0 16px;">
				<p>
				<?php
					printf(
						/* translators: %s is the link to module overview */
						esc_html__( 'This module is disabled, so the settings below have no effect. Enable it in %s first.', 'moksa-for-woocommerce' ),
						'<a href="' . esc_url( $tab_url ) . '">' . esc_html__( 'Module overview', 'moksa-for-woocommerce' ) . '</a>'
					);
				?>
				</p>
			</div>
			<?php
		}
	}

	private function save_general_section(): void {
		// 與 WC core 的 WC_Admin_Settings::save() 驗同一顆 settings nonce，同一種寫法。
		if ( ! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), 'woocommerce-settings' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$registry = Plugin::instance()->modules();
		$posted   = wp_unslash( $_POST );
		foreach ( $registry->all() as $key => $class ) {
			// 只寫「這一頁真的有卡片」的模組。沒渲染的模組不會有 POST 值，
			// 照樣寫入等於每次按儲存就把它關掉 —— 訂單查號搜尋的開關在「進階設定」，
			// 之前就是這樣被無聲關掉的（連帶讓 AI 助手失去全部工具）。
			if ( in_array( $key, self::OVERVIEW_EXCLUDED, true ) ) {
				continue;
			}
			$option = sprintf( 'moksafowo_%s_enabled', $key );
			$value  = isset( $posted[ $option ] ) && 'yes' === $posted[ $option ] ? 'yes' : 'no';
			update_option( $option, $value );
		}
	}
}
