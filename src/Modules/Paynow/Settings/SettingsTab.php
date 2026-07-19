<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '商家憑證', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( '向立即富 (PayNow) 申請取得 WebNo（賣家統編 / 身分證）與商家交易密碼。正式與測試帳號獨立，不可共用。', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_paynow_section',
			],
			[
				'title'   => __( '啟用測試模式', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_paynow_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後所有交易走 PayNow 測試平台。注意：測試平台除「虛擬帳號」外所有交易都會回失敗。上線後請取消勾選。', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( '測試 WebNo', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_paynow_sandbox_web_no',
				'type'     => 'text',
				'desc_tip' => __( '測試環境的賣家統編 / 身分證（身分證英文須大寫）。', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( '測試商家交易密碼', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_paynow_sandbox_trade_password',
				'type'  => 'text',
			],
			[
				'title'    => __( '正式 WebNo', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_paynow_web_no',
				'type'     => 'text',
				'desc_tip' => __( '正式環境的賣家統編 / 身分證（身分證英文須大寫）。', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( '正式商家交易密碼', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_paynow_trade_password',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_paynow_section',
			],

			[
				'title' => __( '付款設定', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_paynow_misc_section',
			],
			[
				'title'    => __( 'EC 平台名稱', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_paynow_ec_platform',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( '送出至 PayNow 的 EC 平台名稱。留空 = 使用網站名稱。', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( '交易內容', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_paynow_order_info',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( '5–200 字。留空 = 自動帶訂單品項名稱。', 'moksa-for-woocommerce' ),
			],
			[
				'title'             => __( 'ATM 繳款期限（天）', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_paynow_atm_deadline_days',
				'type'              => 'number',
				'default'           => 0,
				'desc_tip'          => __( '虛擬帳號繳款期限。0 = 依 PayNow 預設。', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 0,
					'step' => 1,
				],
			],
			[
				'title'             => __( '超商條碼繳款期限（天）', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_paynow_cvs_deadline_days',
				'type'              => 'number',
				'default'           => 0,
				'desc_tip'          => __( '超商條碼繳款期限。0 = 依 PayNow 預設。', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 0,
					'step' => 1,
				],
			],
			[
				'title'             => __( '代碼繳費期限（天）', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_paynow_code_deadline_days',
				'type'              => 'number',
				'default'           => 0,
				'desc_tip'          => __( 'ibon / FamiPort / iCash 繳費期限。0 = 依 PayNow 預設。', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 0,
					'step' => 1,
				],
			],
			[
				'title'   => __( '偵錯日誌', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_paynow_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查訂單異常時開啟。位置：WooCommerce → 狀態 → 日誌。交易密碼等敏感資料不會寫入日誌。', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_paynow_misc_section',
			],

			[
				'title' => __( '啟用的付款方式', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( '勾選要在結帳頁顯示的 PayNow 付款方式。', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_paynow_methods_section',
			],
			[
				'title'   => __( '付款方式', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_paynow_enabled_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'moksafowo_paynow_credit'             => __( '信用卡', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_credit_installment' => __( '信用卡分期', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_webatm'             => __( 'WebATM', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_atm'                => __( 'ATM 虛擬帳號', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_cvs'                => __( '超商條碼繳費', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_ibon'               => __( 'ibon 代碼繳費', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_famiport'           => __( 'FamiPort 代碼繳費', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_icash'              => __( 'iCash 錢包', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_unionpay'           => __( '銀聯卡', 'moksa-for-woocommerce' ),
				],
				'desc'    => __( '勾選的付款方式才會出現在結帳頁，未勾選不會出現。', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_paynow_methods_section',
			],
		];
	}
}
