<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Settings;

use MoksaWeb\Mowc\Plugin;

defined( 'ABSPATH' ) || exit;

final class SettingsPage extends \WC_Settings_Page {

	public function __construct() {
		$this->id    = SettingsTab::TAB_ID;
		$this->label = __( 'Moksa 電商工具', 'mo-ectools' );
		parent::__construct();

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
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
			'ecpay' => [
				'enable_key' => 'ecpay',
				'label'      => __( '綠界金流', 'mo-ectools' ),
				'banner_name' => __( '綠界金流', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\Ecpay\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'ecpay-shipping' => [
				'enable_key' => 'ecpay_shipping',
				'label'      => __( '綠界物流', 'mo-ectools' ),
				'banner_name' => __( '綠界物流', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\EcpayShipping\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'ecpay-invoice' => [
				'enable_key' => 'ecpay_invoice',
				'label'      => __( '綠界電子發票', 'mo-ectools' ),
				'banner_name' => __( '綠界電子發票', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\EcpayInvoice\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'newebpay' => [
				'enable_key' => 'newebpay',
				'label'      => __( '藍新金流', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\Newebpay\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'newebpay-shipping' => [
				'enable_key' => 'newebpay_shipping',
				'label'      => __( '藍新物流', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\NewebpayShipping\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'ezpay-invoice' => [
				'enable_key' => 'ezpay_invoice',
				'label'      => __( 'ezPay 電子發票', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\EzpayInvoice\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'smilepay-invoice' => [
				'enable_key' => 'smilepay_invoice',
				'label'      => __( 'SmilePay 電子發票', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\SmilepayInvoice\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'paynow-invoice' => [
				'enable_key' => 'paynow_invoice',
				'label'      => __( 'PayNow 電子發票', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\PaynowInvoice\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'amego-invoice' => [
				'enable_key' => 'amego_invoice',
				'label'      => __( 'AMEGO 電子發票', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\AmegoInvoice\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'linepay' => [
				'enable_key'  => 'linepay',
				'label'       => __( 'LINE Pay', 'mo-ectools' ),
				'banner_name' => __( 'LINE Pay 台灣', 'mo-ectools' ),
				'tab_class'   => 'MoksaWeb\\Mowc\\Modules\\Linepay\\Settings\\SettingsTab',
				'tab_method'  => 'get_settings',
				'tab_arg'     => '',
			],
			'moksafowo-payuni-payment' => [
				'enable_key' => 'payuni',
				'label'      => __( 'PAYUNi 金流', 'mo-ectools' ),
				'banner_name' => __( 'PAYUNi 統一金流', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\Payuni\\Settings\\SettingsTab',
				'tab_method' => 'get_settings_for_payment_section',
				'tab_arg'    => null,
			],
			'moksafowo-payuni-shipping' => [
				'enable_key' => 'payuni_shipping',
				'label'      => __( 'PAYUNi 物流', 'mo-ectools' ),
				'banner_name' => __( 'PAYUNi 物流', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\PayuniShipping\\Settings\\SettingsTab',
				'tab_method' => 'get_settings_for_shipping_section',
				'tab_arg'    => null,
			],
			'smilepay-shipping' => [
				'enable_key' => 'smilepay_shipping',
				'label'      => __( '速買配 物流', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\SmilepayShipping\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'smilepay-payment' => [
				'enable_key' => 'smilepay',
				'label'      => __( 'SmilePay 速買配 金流', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\Smilepay\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'pchomepay' => [
				'enable_key' => 'pchomepay',
				'label'      => __( 'PChomePay 支付連', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\Pchomepay\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'tappay' => [
				'enable_key' => 'tappay',
				'label'      => __( 'TapPay 拍付', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\Tappay\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'paynow' => [
				'enable_key' => 'paynow',
				'label'      => __( 'PayNow 立即富', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\Paynow\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
			'shopline-payments' => [
				'enable_key' => 'shopline_payments',
				'label'      => __( 'Shopline Payments', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\ShoplinePayments\\Settings\\SettingsTab',
				'tab_method' => 'get_settings',
				'tab_arg'    => null,
			],
		];
	}

	public function get_sections(): array {
		$sections = [
			''         => __( '總覽', 'mo-ectools' ),
			'advanced' => __( '進階設定', 'mo-ectools' ),
		];
		foreach ( self::module_descriptors() as $section => $desc ) {
			if ( 'yes' === get_option( 'moksafowo_' . $desc['enable_key'] . '_enabled', 'no' ) ) {
				$sections[ $section ] = $desc['label'];
			}
		}
		return apply_filters( 'woocommerce_get_sections_' . $this->id, $sections ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC core convention extension point.
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
			$ref = new \ReflectionClass( $class );
			$obj = $ref->newInstanceWithoutConstructor();
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
				'title' => __( '物流共用設定', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '所有物流模組共用的行為設定。', 'mo-ectools' ),
				'id'    => 'moksafowo_shipping_common_section',
			],
			[
				'title'         => __( '批次列印介面', 'mo-ectools' ),
				'id'            => 'moksafowo_shipping_bulk_print_mode_basic',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'desc'          => __( '基本 — 走 WooCommerce 內建下拉選單，自己勾訂單再選批次操作。', 'mo-ectools' ),
				'checkboxgroup' => 'start',
				'class'         => 'moksafowo-bulk-print-mode',
			],
			[
				'id'            => 'moksafowo_shipping_bulk_print_mode_advanced',
				'type'          => 'checkbox',
				'default'       => 'no',
				'desc'          => __( '進階 — 點工具列「綠界 超商/宅配標籤」按鈕後跳出彈窗，自動過濾未印單，一次勾選一鍵列印（防漏印）。', 'mo-ectools' ),
				'checkboxgroup' => 'end',
				'class'         => 'moksafowo-bulk-print-mode',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shipping_common_section',
			],

			// 自訂訂單狀態（可選擇性停用避免 status dropdown 過長）
			[
				'title' => __( '自訂訂單狀態', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '物流模組共用的自訂訂單狀態。停用某狀態 → 不出現在訂單編輯頁與批次操作下拉，但資料庫既有訂單仍保留該狀態。', 'mo-ectools' ),
				'id'    => 'moksafowo_shipping_status_section',
			],
			[
				'title'         => __( '已出貨', 'mo-ectools' ),
				'id'            => 'moksafowo_shipping_status_moksafowo_shipped_enabled',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'desc'          => __( '物流商已收件並開始配送', 'mo-ectools' ),
				'checkboxgroup' => 'start',
			],
			[
				'id'            => 'moksafowo_shipping_status_moksafowo_cvs_arrived_enabled',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'desc'          => __( '已到店待取（超商取貨流程專用）', 'mo-ectools' ),
				'checkboxgroup' => '',
			],
			[
				'id'            => 'moksafowo_shipping_status_moksafowo_store_closed_enabled',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'desc'          => __( '門市關轉（超商門市關閉，需重選門市）', 'mo-ectools' ),
				'checkboxgroup' => 'end',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shipping_status_section',
			],

			// 訂單狀態 badge 顏色 — 緊湊 color grid（custom field type）
			[
				'title' => __( '訂單狀態顏色', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( 'WordPress 後台訂單列表狀態標籤的底色與文字顏色。點色塊用 WordPress 內建選色器選色，右邊即時預覽。', 'mo-ectools' ),
				'id'    => 'moksafowo_shipping_status_color_section',
			],
			[
				'type' => 'mowp_status_color_grid',
				'id'   => 'moksafowo_status_color_grid',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shipping_status_color_section',
			],

			// 台灣地址工具
			[
				'title' => __( '台灣地址工具', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '結帳頁地址欄位的台灣本地化加強。傳統結帳與區塊結帳都相容。', 'mo-ectools' ),
				'id'    => 'moksafowo_tw_address_section',
			],
			[
				'title'         => __( '啟用台灣縣市/鄉鎮下拉選單', 'mo-ectools' ),
				'id'            => 'moksafowo_tw_address_dropdown_enabled',
				'type'          => 'checkbox',
				'default'       => 'no',
				'desc'          => __( '把地址欄位的「縣 / 市」「鄉 / 鎮 / 區」改成下拉選單，避免顧客手動拼錯。', 'mo-ectools' ),
				'checkboxgroup' => 'start',
			],
			[
				'id'            => 'moksafowo_tw_address_postcode_autofill',
				'type'          => 'checkbox',
				'desc'          => __( '選完縣市鄉鎮後自動帶入郵遞區號（依政府最新郵遞區號表）', 'mo-ectools' ),
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'id'            => 'moksafowo_tw_address_name_swap',
				'type'          => 'checkbox',
				'desc'          => __( '姓名對調 — 把 WooCommerce 預設「名字 / 姓氏」順序改成台灣慣例「姓氏 / 名字」。', 'mo-ectools' ),
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'id'            => 'moksafowo_tw_address_hide_country',
				'type'          => 'checkbox',
				'desc'          => __( '隱藏「國家 / 地區」欄位（單一台灣站適用）', 'mo-ectools' ),
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'id'            => 'moksafowo_tw_address_reorder_fields',
				'type'          => 'checkbox',
				'desc'          => __( '啟用台式欄位順序與寬度（下方拖拉設定）— 傳統結帳完整生效，區塊結帳只同步順序（強制 2 欄）。', 'mo-ectools' ),
				'default'       => 'no',
				'checkboxgroup' => 'end',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_tw_address_section',
			],

			// 台灣欄位順序與寬度（field manager UI）
			[
				'title' => __( '台灣欄位順序與寬度', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '拖曳重排欄位、選 50% 或 100% 寬度。需勾選上方「啟用台式欄位順序」才會套用到結帳頁。', 'mo-ectools' ),
				'id'    => 'moksafowo_tw_field_manager_section',
			],
			[
				'title' => __( '欄位順序與寬度', 'mo-ectools' ),
				'type'  => 'mowp_field_manager',
				'desc'  => __( '半寬欄位需兩兩配對才會並排（例：姓氏 50% + 名字 50% → 同一行）。落單的 50% 會自動退回 100%。區塊結帳不吃寬度設定。', 'mo-ectools' ),
				'id'    => 'moksafowo_tw_address_field_layout',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_tw_field_manager_section',
			],

			// 訂單查號搜尋
			[
				'title' => __( '訂單查號搜尋', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '讓 WooCommerce 訂單搜尋框與 Ctrl+K 命令面板認得台灣特有號碼。只搜尋已啟用模組的號碼。', 'mo-ectools' ),
				'id'    => 'moksafowo_order_lookup_section',
			],
			[
				'title'   => __( '啟用訂單查號搜尋', 'mo-ectools' ),
				'id'      => 'moksafowo_order_lookup_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '開啟後，可在訂單列表搜尋框與命令面板用下列號碼查訂單。', 'mo-ectools' ),
			],
			[
				'title'         => __( '搜尋號碼類型', 'mo-ectools' ),
				'desc'          => __( '發票號碼', 'mo-ectools' ),
				'id'            => 'moksafowo_order_lookup_field_invoice',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			],
			[
				'desc'          => __( '物流單號', 'mo-ectools' ),
				'id'            => 'moksafowo_order_lookup_field_shipping',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '金流交易序號', 'mo-ectools' ),
				'id'            => 'moksafowo_order_lookup_field_payment',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '統一編號', 'mo-ectools' ),
				'id'            => 'moksafowo_order_lookup_field_ubn',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'ATM 虛擬帳號', 'mo-ectools' ),
				'id'            => 'moksafowo_order_lookup_field_atm',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '超商繳費代碼', 'mo-ectools' ),
				'id'            => 'moksafowo_order_lookup_field_cvs',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '卡末四碼', 'mo-ectools' ),
				'id'            => 'moksafowo_order_lookup_field_card',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '黑貓追蹤號', 'mo-ectools' ),
				'id'            => 'moksafowo_order_lookup_field_tcat',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => 'end',
				'desc_tip'      => __( '勾選要納入搜尋的號碼類型。欄位越多搜尋越慢，常用的勾起來即可。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_order_lookup_section',
			],

			// Moksa AI（Beta，需 WP 7.0 AI Client）
			[
				'title' => __( 'Moksa AI 助手（Beta）', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '在後台用自然語言查訂單的 AI 助手。啟用後,後台右下角會出現浮動對話窗。<br><strong>目前會做</strong>:① 用發票號 / 物流單號 / 金流交易序號查單筆訂單;② 查訂單數量與各狀態分布(例:待出貨幾筆)。<br><strong>前置需求</strong>:WordPress 7.0(內建 AI Client)+ 在「設定 → Connectors」設好 OpenAI / Anthropic / Google 任一把 AI 金鑰(費用走商家自己的金鑰)。WordPress 低於 7.0 時此功能不會出現。', 'mo-ectools' ),
				'id'    => 'moksafowo_ai_assistant_section',
			],
			[
				'title'   => __( '啟用 Moksa AI', 'mo-ectools' ),
				'id'      => 'moksafowo_ai_assistant_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '開啟後台右下角的浮動 AI 對話窗。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ai_assistant_section',
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
		];
		foreach ( $registry->all() as $key => $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$instance = new $class();
			$category = $instance->category();
			// 工具類（訂單查號搜尋）的設定全部移到「進階設定」分頁，總覽不放卡片。
			if ( 'tools' === $category ) {
				continue;
			}
			if ( ! isset( $by_category[ $category ] ) ) {
				$by_category[ $category ] = [];
			}
			$by_category[ $category ][ $key ] = $instance;
		}

		$category_labels = [
			'payment'  => __( '金流模組', 'mo-ectools' ),
			'shipping' => __( '物流模組', 'mo-ectools' ),
			'invoice'  => __( '電子發票模組', 'mo-ectools' ),
			'checkout' => __( '結帳工具', 'mo-ectools' ),
			'tools'    => __( '工具', 'mo-ectools' ),
		];

		$tab = SettingsTab::TAB_ID;

		Ui::open_shell();
		Ui::intro(
			__( 'Moksa — 台灣工具包', 'mo-ectools' ),
			__( '啟用您需要的模組。各家可任意組合搭配；金流、物流、發票完全解耦。', 'mo-ectools' )
		);

		foreach ( $by_category as $category => $entries ) {
			if ( $entries === [] ) {
				continue;
			}
			$cards = [];
			foreach ( $entries as $key => $module ) {
				$option        = sprintf( 'moksafowo_%s_enabled', $key );
				$enabled       = 'yes' === get_option( $option, 'no' );
				$section_slug  = $module->settings_section();
				$cards[]       = [
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
				<p><?php
					printf(
						/* translators: %s is the link to module overview */
						esc_html__( '此模組目前已停用，下方設定不會生效。請至 %s 啟用後再設定。', 'mo-ectools' ),
						'<a href="' . esc_url( $tab_url ) . '">' . esc_html__( '模組總覽', 'mo-ectools' ) . '</a>'
					);
				?></p>
			</div>
			<?php
		}
	}

	private function save_general_section(): void {
		$registry = Plugin::instance()->modules();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC's settings page handles its own nonce upstream.
		$posted = wp_unslash( $_POST );
		foreach ( $registry->all() as $key => $class ) {
			$option = sprintf( 'moksafowo_%s_enabled', $key );
			$value  = isset( $posted[ $option ] ) && 'yes' === $posted[ $option ] ? 'yes' : 'no';
			update_option( $option, $value );
		}
	}
}
