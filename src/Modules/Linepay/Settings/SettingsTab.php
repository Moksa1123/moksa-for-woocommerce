<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Linepay\Settings;

use WC_Admin_Settings;
use WC_Settings_Page;

defined( 'ABSPATH' ) || exit;

class SettingsTab extends WC_Settings_Page {

	public function __construct() {

		$this->id    = 'moksafowo-linepay';
		$this->label = __( 'LINE Pay', 'moksa-for-woocommerce' );

		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );
		add_action( 'woocommerce_sections_' . $this->id, array( $this, 'output_sections' ) );

		parent::__construct();
	}

	public function get_sections() {

		$sections = array(
			'' => __( '付款設定', 'moksa-for-woocommerce' ),
		);

		return apply_filters( 'moksafowo_get_sections_' . $this->id, $sections );
	}

	public function get_settings( $current_section = '' ) {

		$settings = apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- moksafowo_linepay_ is wpbrewer fork BC prefix per CLAUDE.md fork-then-patch.
			'moksafowo_linepay_payment_settings',
			array(
				array(
					'title' => __( '一般', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'id'    => 'moksafowo_linepay_general_setting',
				),
				array(
					'title'   => __( '偵錯日誌', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => sprintf(
						/* translators: %s = view logs link */
						__( '排查訂單異常時開啟。位置：WooCommerce → 狀態 → 日誌。 %s', 'moksa-for-woocommerce' ),
						$this->get_log_link()
					),
					'id'      => 'moksafowo_linepay_debug_log_enabled',
				),
				array(
					'title'   => __( '結帳頁顯示 LINE Pay 圖示', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => __( '顯示官方 LINE Pay logo 在結帳頁的付款方式選項旁。', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_linepay_display_logo_enabled',
				),
				array(
					'title'   => __( '付款失敗時改成什麼狀態', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'options' => wc_get_order_statuses(),
					'desc'    => __( '顧客 LINE Pay 付款失敗時，訂單自動轉成這個狀態。', 'moksa-for-woocommerce' ),
					'default' => 'wc-failed',
					'id'      => 'moksafowo_linepay_payment_fail_order_status',
				),
				array(
					'title'   => __( '詳細狀態加進訂單備註', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => __( '把每次 LINE Pay 回傳的詳細狀態寫進訂單備註（測試 / 排查時開啟，正式環境關閉避免備註過多）。', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_linepay_detail_status_note_enabled',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'moksafowo_linepay_general_setting',
				),
				array(
					'title' => __( '商家憑證', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'desc'  => __( '從 LINE Pay 商家後台「管理者中心 → 連結金鑰管理」複製過來。', 'moksa-for-woocommerce' ),
					'id'    => 'moksafowo_linepay_api_settings',
				),
				array(
					'title'   => __( '啟用測試模式', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => __( '勾選後，所有交易走 LINE Pay 測試環境不會真扣款。上線後請取消勾選。', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_linepay_sandboxmode_enabled',
				),
				array(
					'title'   => __( '測試 Channel ID', 'moksa-for-woocommerce' ),
					'type'    => 'text',
					'default' => '',
					'id'      => 'moksafowo_linepay_sandbox_channel_id',
				),
				array(
					'title'   => __( '測試 Channel Secret', 'moksa-for-woocommerce' ),
					'type'    => 'text',
					'default' => '',
					'id'      => 'moksafowo_linepay_sandbox_channel_secret',
				),
				array(
					'title'   => __( '正式 Channel ID', 'moksa-for-woocommerce' ),
					'type'    => 'text',
					'default' => '',
					'id'      => 'moksafowo_linepay_channel_id',
				),
				array(
					'title'   => __( '正式 Channel Secret', 'moksa-for-woocommerce' ),
					'type'    => 'text',
					'default' => '',
					'id'      => 'moksafowo_linepay_channel_secret',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'moksafowo_linepay_api_settings',
				),
			)
		);

		return apply_filters( 'moksafowo_get_settings_' . $this->id, $settings, $current_section );
	}

	public function output(): void {
		global $current_section;
		$settings = $this->get_settings( $current_section );
		WC_Admin_Settings::output_fields( $settings );
	}

	public function save(): void {
		global $current_section;
		$settings = $this->get_settings( $current_section );
		WC_Admin_Settings::save_fields( $settings );
	}

	protected function get_log_link(): string {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . __( '查看日誌', 'moksa-for-woocommerce' ) . '</a>';
	}
}
