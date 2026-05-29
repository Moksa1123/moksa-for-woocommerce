<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Settings;

use MoksaWeb\Mowc\Plugin;

defined( 'ABSPATH' ) || exit;

final class SettingsPage extends \WC_Settings_Page {

	public function __construct() {
		$this->id    = SettingsTab::TAB_ID;
		$this->label = __( 'Moksa', 'mo-ectools' );
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

		// 全 Moksa tab 共用視覺優化 — section 之間加分隔、heading 加左側色條
		add_action( 'admin_print_styles', [ $this, 'inject_settings_polish_css' ] );

		// 只在 ECPay 三組子 section + PAYUNi shipping 載入
		if ( ! in_array( $section, [ 'ecpay', 'ecpay-shipping', 'ecpay-invoice', 'payuni-shipping' ], true ) ) {
			return;
		}
		// 用 filemtime 當版號，admin JS 改動每次自動 cache-bust，不靠 plugin VERSION bump。
		$path    = MOWC_PLUGIN_DIR . 'assets/admin/ecpay-sandbox-toggle.js';
		$version = file_exists( $path ) ? (string) filemtime( $path ) : MOWC_VERSION;
		wp_enqueue_script(
			'mo-ecpay-sandbox-toggle',
			MOWC_PLUGIN_URL . 'assets/admin/ecpay-sandbox-toggle.js',
			[],
			$version,
			true
		);
	}

	public function inject_settings_polish_css(): void {
		?>
		<style>
			/* Moksa settings tab — 全頁優化（h2 + p + form-table 包成一個 card） */
			.mowp-section-card{
				margin:20px 0 0;
				background:#fff;
				border:1px solid #e5e7eb;
				border-radius:8px;
				overflow:hidden;
			}
			.mowp-section-card > h2{
				position:relative;
				padding:14px 44px 14px 24px !important;
				margin:0 !important;
				background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);
				border-bottom:1px solid #e5e7eb;
				font-size:14px;
				font-weight:600;
				color:#0f172a;
				line-height:1.4;
				cursor:pointer;
				user-select:none;
				transition:background .15s ease;
			}
			.mowp-section-card > h2:hover{
				background:linear-gradient(180deg,#f1f5f9 0%,#e2e8f0 100%);
			}
			/* Chevron 箭頭 — 點 heading 收合 */
			.mowp-section-card > h2 .mo-h2-chevron{
				position:absolute;
				right:20px;
				top:50%;
				transform:translateY(-50%) rotate(0deg);
				width:8px; height:8px;
				border:solid #64748b;
				border-width:0 2px 2px 0;
				margin-top:-3px;
				transition:transform .2s ease;
			}
			.mowp-section-card.is-collapsed > h2 .mo-h2-chevron{
				transform:translateY(-50%) rotate(-135deg);
				margin-top:0;
			}
			.mowp-section-card:not(.is-collapsed) > h2 .mo-h2-chevron{
				transform:translateY(-50%) rotate(45deg);
				margin-top:-3px;
			}
			.mowp-section-card.is-collapsed > h2{
				border-bottom:0;
			}
			.mowp-section-card.is-collapsed > h2 .mo-h2-text::after{
				display:none;
			}
			.mowp-section-card.is-collapsed > p,
			.mowp-section-card.is-collapsed > div:not(.mowp-section-card),
			.mowp-section-card.is-collapsed > table.form-table{
				display:none !important;
			}
			/* heading 文字外包一層 span（由 JS 加），漸層 accent 跟著文字長度延伸 */
			.mowp-section-card > h2 .mo-h2-text{
				position:relative;
				display:inline-block;
			}
			.mowp-section-card > h2 .mo-h2-text::after{
				content:"";
				position:absolute;
				left:0;
				bottom:-14px;
				width:100%;
				height:2px;
				background:linear-gradient(90deg,#f97316 0%,rgba(249,115,22,0) 100%);
				z-index:1;
				pointer-events:none;
			}
			/* desc 段：純文字直接接 heading，不另外開區塊裝飾 */
			.mowp-section-card > p,
			.mowp-section-card > div:not(.mowp-section-card){
				margin:0 !important;
				padding:8px 24px 4px !important;
				color:#475569;
				font-size:13px;
				line-height:1.5;
			}
			.mowp-section-card > table.form-table{
				margin:0 !important;
			}
			/* form-table 內 cells 加左 padding 對齊 card heading（24px from card edge）；
			   不改 right padding 避免影響 WC help-tip absolute 定位邏輯 */
			.mowp-section-card table.form-table th{
				padding-left:24px !important;
			}
			.mowp-section-card table.form-table td:not([colspan]){
				padding-left:0 !important;
			}
			/* full-width row (colspan=2) — 也對齊 24px，跟 th 一致，不另搞一套 */
			.mowp-section-card table.form-table td[colspan]{
				padding-left:24px !important;
				padding-right:24px !important;
			}
			body.woocommerce_page_wc-settings #mainform p.submit{
				margin-top:24px;
			}
		</style>
		<script>
		// WC settings 沒提供 wrapper hook，用 JS 把 h2 + p + form-table 三組元素打包成 .mowp-section-card
		(function(){
			var STORAGE_KEY = 'mowp_settings_collapsed_v1';
			function loadCollapsed(){
				try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {}; } catch(e){ return {}; }
			}
			function saveCollapsed(state){
				try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch(e){}
			}
			function group(){
				var collapsedState = loadCollapsed();
				var heads = document.querySelectorAll('#mainform h2');
				heads.forEach(function(h2, idx){
					if(h2.classList.contains('screen-reader-text') || h2.closest('.mowp-section-card') || h2.closest('.mowp-intro') || h2.closest('.mowp-subsection-banner')) return;
					// 把 h2 內的文字節點包進 span — accent line 跟著文字寬度延伸
					if(!h2.querySelector('.mo-h2-text')){
						var span = document.createElement('span');
						span.className = 'mo-h2-text';
						while(h2.firstChild) span.appendChild(h2.firstChild);
						h2.appendChild(span);
					}
					// chevron icon for collapse indicator
					if(!h2.querySelector('.mo-h2-chevron')){
						var chev = document.createElement('span');
						chev.className = 'mo-h2-chevron';
						chev.setAttribute('aria-hidden', 'true');
						h2.appendChild(chev);
					}
					var card = document.createElement('div');
					card.className = 'mowp-section-card';
					// 用 heading 文字當 stable key（i18n 後仍同名）
					var key = h2.querySelector('.mo-h2-text').textContent.trim();
					card.setAttribute('data-mo-key', key);
					if(collapsedState[key]) card.classList.add('is-collapsed');
					h2.parentNode.insertBefore(card, h2);
					card.appendChild(h2);
					// click toggle + a11y
					h2.setAttribute('role','button');
					h2.setAttribute('tabindex','0');
					h2.setAttribute('aria-expanded', collapsedState[key] ? 'false' : 'true');
					function toggle(){
						card.classList.toggle('is-collapsed');
						var nowCollapsed = card.classList.contains('is-collapsed');
						h2.setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
						var st = loadCollapsed();
						if(nowCollapsed) st[key] = 1; else delete st[key];
						saveCollapsed(st);
					}
					h2.addEventListener('click', toggle);
					h2.addEventListener('keydown', function(e){
						if(e.key === 'Enter' || e.key === ' '){
							e.preventDefault();
							toggle();
						}
					});
					var next = card.nextElementSibling;
					while(next){
						var isDesc  = (next.tagName === 'P' || next.tagName === 'DIV') && !next.classList.contains('mowp-section-card');
						var isTable = next.tagName === 'TABLE' && /\bform-table\b/.test(next.className);
						if(!isDesc && !isTable) break;
						var temp = next.nextElementSibling;
						card.appendChild(next);
						next = temp;
					}
				});
			}
			// 批次列印介面 — 兩個 checkbox 互斥（XOR：勾一個自動取消另一個）
			function bindMutualExclusion(){
				var inputs = document.querySelectorAll('#mo_shipping_bulk_print_mode_basic, #mo_shipping_bulk_print_mode_advanced');
				if(inputs.length < 2) return;
				inputs.forEach(function(input){
					input.addEventListener('change', function(){
						if(!this.checked) return;
						inputs.forEach(function(other){
							if(other !== input) other.checked = false;
						});
					});
				});
			}
			function init(){ group(); bindMutualExclusion(); }
			if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', init);
			else init();
		})();
		</script>
		<?php
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
			'payuni-payment' => [
				'enable_key' => 'payuni',
				'label'      => __( 'PAYUNi 金流', 'mo-ectools' ),
				'banner_name' => __( 'PAYUNi 統一金流', 'mo-ectools' ),
				'tab_class'  => 'MoksaWeb\\Mowc\\Modules\\Payuni\\Settings\\SettingsTab',
				'tab_method' => 'get_settings_for_payment_section',
				'tab_arg'    => null,
			],
			'payuni-shipping' => [
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
			if ( 'yes' === get_option( 'mo_' . $desc['enable_key'] . '_enabled', 'no' ) ) {
				$sections[ $section ] = $desc['label'];
			}
		}
		return apply_filters( 'woocommerce_get_sections_' . $this->id, $sections );
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
	}

	private function advanced_fields(): array {
		return [
			// 物流共用
			[
				'title' => __( '物流共用設定', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '所有物流模組共用的行為設定。', 'mo-ectools' ),
				'id'    => 'mo_shipping_common_section',
			],
			[
				'title'         => __( '批次列印介面', 'mo-ectools' ),
				'id'            => 'mo_shipping_bulk_print_mode_basic',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'desc'          => __( '基本 — 走 WooCommerce 內建下拉選單，自己勾訂單再選批次操作。', 'mo-ectools' ),
				'checkboxgroup' => 'start',
				'class'         => 'mo-bulk-print-mode',
			],
			[
				'id'            => 'mo_shipping_bulk_print_mode_advanced',
				'type'          => 'checkbox',
				'default'       => 'no',
				'desc'          => __( '進階 — 點工具列「綠界 超商/宅配標籤」按鈕後跳出彈窗，自動過濾未印單，一次勾選一鍵列印（防漏印）。', 'mo-ectools' ),
				'checkboxgroup' => 'end',
				'class'         => 'mo-bulk-print-mode',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_shipping_common_section',
			],

			// 自訂訂單狀態（可選擇性停用避免 status dropdown 過長）
			[
				'title' => __( '自訂訂單狀態', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '物流模組共用的自訂訂單狀態。停用某狀態 → 不出現在訂單編輯頁與批次操作下拉，但資料庫既有訂單仍保留該狀態。', 'mo-ectools' ),
				'id'    => 'mo_shipping_status_section',
			],
			[
				'title'         => __( '已出貨', 'mo-ectools' ),
				'id'            => 'mo_shipping_status_mo_shipped_enabled',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'desc'          => __( '物流商已收件並開始配送', 'mo-ectools' ),
				'checkboxgroup' => 'start',
			],
			[
				'id'            => 'mo_shipping_status_mo_cvs_arrived_enabled',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'desc'          => __( '已到店待取（超商取貨流程專用）', 'mo-ectools' ),
				'checkboxgroup' => '',
			],
			[
				'id'            => 'mo_shipping_status_mo_store_closed_enabled',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'desc'          => __( '門市關轉（超商門市關閉，需重選門市）', 'mo-ectools' ),
				'checkboxgroup' => 'end',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_shipping_status_section',
			],

			// 訂單狀態 badge 顏色 — 緊湊 color grid（custom field type）
			[
				'title' => __( '訂單狀態顏色', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( 'WordPress 後台訂單列表狀態標籤的底色與文字顏色。點色塊用 WordPress 內建選色器選色，右邊即時預覽。', 'mo-ectools' ),
				'id'    => 'mo_shipping_status_color_section',
			],
			[
				'type' => 'mowp_status_color_grid',
				'id'   => 'mo_status_color_grid',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_shipping_status_color_section',
			],

			// 台灣地址工具
			[
				'title' => __( '台灣地址工具', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '結帳頁地址欄位的台灣本地化加強。傳統結帳與區塊結帳都相容。', 'mo-ectools' ),
				'id'    => 'mo_tw_address_section',
			],
			[
				'title'         => __( '啟用台灣縣市/鄉鎮下拉選單', 'mo-ectools' ),
				'id'            => 'mo_tw_address_dropdown_enabled',
				'type'          => 'checkbox',
				'default'       => 'no',
				'desc'          => __( '把地址欄位的「縣 / 市」「鄉 / 鎮 / 區」改成下拉選單，避免顧客手動拼錯。', 'mo-ectools' ),
				'checkboxgroup' => 'start',
			],
			[
				'id'            => 'mo_tw_address_postcode_autofill',
				'type'          => 'checkbox',
				'desc'          => __( '選完縣市鄉鎮後自動帶入郵遞區號（依政府最新郵遞區號表）', 'mo-ectools' ),
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'id'            => 'mo_tw_address_name_swap',
				'type'          => 'checkbox',
				'desc'          => __( '姓名對調 — 把 WooCommerce 預設「名字 / 姓氏」順序改成台灣慣例「姓氏 / 名字」。', 'mo-ectools' ),
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'id'            => 'mo_tw_address_hide_country',
				'type'          => 'checkbox',
				'desc'          => __( '隱藏「國家 / 地區」欄位（單一台灣站適用）', 'mo-ectools' ),
				'default'       => 'no',
				'checkboxgroup' => '',
			],
			[
				'id'            => 'mo_tw_address_reorder_fields',
				'type'          => 'checkbox',
				'desc'          => __( '啟用台式欄位順序與寬度（下方拖拉設定）— 傳統結帳完整生效，區塊結帳只同步順序（強制 2 欄）。', 'mo-ectools' ),
				'default'       => 'no',
				'checkboxgroup' => 'end',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_tw_address_section',
			],

			// 台灣欄位順序與寬度（field manager UI）
			[
				'title' => __( '台灣欄位順序與寬度', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '拖曳重排欄位、選 50% 或 100% 寬度。需勾選上方「啟用台式欄位順序」才會套用到結帳頁。', 'mo-ectools' ),
				'id'    => 'mo_tw_field_manager_section',
			],
			[
				'title' => __( '欄位順序與寬度', 'mo-ectools' ),
				'type'  => 'mowp_field_manager',
				'desc'  => __( '半寬欄位需兩兩配對才會並排（例：姓氏 50% + 名字 50% → 同一行）。落單的 50% 會自動退回 100%。區塊結帳不吃寬度設定。', 'mo-ectools' ),
				'id'    => 'mo_tw_address_field_layout',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_tw_field_manager_section',
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
				$option        = sprintf( 'mo_%s_enabled', $key );
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
		$enabled = 'yes' === get_option( 'mo_' . $desc['enable_key'] . '_enabled', 'no' );

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
			$option = sprintf( 'mo_%s_enabled', $key );
			$value  = isset( $posted[ $option ] ) && 'yes' === $posted[ $option ] ? 'yes' : 'no';
			update_option( $option, $value );
		}
	}
}
