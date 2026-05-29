<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Smilepay\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '商家憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '從 SmilePay 後台「會員專區 → 系統識別資料」取得 Dcvc / Rvg2c / Verify_key / 商家驗證參數 (Mid)。SmilePay 沒有公開測試 host，沙箱與正式同網域，由 Dcvc 區分。', 'mo-ectools' ),
				'id'    => 'mo_smilepay_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'mo_smilepay_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後改用 SmilePay 公開測試商家（Dcvc=107）進行交易，不會真扣款。上線後請取消勾選。', 'mo-ectools' ),
			],
			[
				'title' => __( 'Dcvc — 商家代號', 'mo-ectools' ),
				'id'    => 'mo_smilepay_dcvc',
				'type'  => 'text',
			],
			[
				'title' => __( 'Rvg2c — 商家參數碼', 'mo-ectools' ),
				'id'    => 'mo_smilepay_rvg2c',
				'type'  => 'text',
			],
			[
				'title' => __( 'Verify_key — 商家檢查碼', 'mo-ectools' ),
				'id'    => 'mo_smilepay_verify_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Mid — 商家驗證參數', 'mo-ectools' ),
				'id'    => 'mo_smilepay_mid',
				'type'  => 'text',
				'desc'  => __( '回傳驗證用（計算 Mid_smilepay 防偽造）。留空則略過回傳簽章驗證（不建議）。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_smilepay_section',
			],

			[
				'title' => __( '付款設定', 'mo-ectools' ),
				'type'  => 'title',
				'id'    => 'mo_smilepay_misc_section',
			],
			[
				'title'    => __( '信用卡分期期數', 'mo-ectools' ),
				'id'       => 'mo_smilepay_installment',
				'type'     => 'text',
				'default'  => '3',
				'desc_tip' => __( '逗號分隔（例 3,6,12）。信用卡分期 gateway 會使用第一個有效期數。', 'mo-ectools' ),
			],
			[
				'title'             => __( 'ATM 繳款期限（天）', 'mo-ectools' ),
				'id'                => 'mo_smilepay_atm_deadline_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( '顧客取得虛擬帳號後幾天內須完成付款（1-720）。', 'mo-ectools' ),
				'custom_attributes' => [ 'min' => 1, 'max' => 720, 'step' => 1 ],
			],
			[
				'title'             => __( '超商條碼繳款期限（天）', 'mo-ectools' ),
				'id'                => 'mo_smilepay_barcode_deadline_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( '顧客取得繳費條碼後幾天內須完成付款（1-50）。', 'mo-ectools' ),
				'custom_attributes' => [ 'min' => 1, 'max' => 50, 'step' => 1 ],
			],
			[
				'title'             => __( 'ibon 繳款期限（天）', 'mo-ectools' ),
				'id'                => 'mo_smilepay_ibon_deadline_days',
				'type'              => 'number',
				'default'           => 6,
				'desc_tip'          => __( '顧客取得繳費代碼後幾天內須完成付款（1-6）。', 'mo-ectools' ),
				'custom_attributes' => [ 'min' => 1, 'max' => 6, 'step' => 1 ],
			],
			[
				'title'             => __( 'FamiPort 繳款期限（天）', 'mo-ectools' ),
				'id'                => 'mo_smilepay_famiport_deadline_days',
				'type'              => 'number',
				'default'           => 6,
				'desc_tip'          => __( '顧客取得繳費代碼後幾天內須完成付款（1-6）。', 'mo-ectools' ),
				'custom_attributes' => [ 'min' => 1, 'max' => 6, 'step' => 1 ],
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'mo_smilepay_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查訂單異常時開啟。位置：WooCommerce → 狀態 → 日誌（來源 smilepay-payment）。憑證不會寫入日誌。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_smilepay_misc_section',
			],

			[
				'title' => __( '啟用的付款方式', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '勾選要在結帳頁顯示的 SmilePay 付款方式。各付款方式的標題 / 描述 / 啟用另在「WooCommerce → 付款方式」分頁設定。', 'mo-ectools' ),
				'id'    => 'mo_smilepay_methods_section',
			],
			[
				'title'   => __( '付款方式', 'mo-ectools' ),
				'id'      => 'mo_smilepay_enabled_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'mo_smilepay_credit'             => __( '信用卡', 'mo-ectools' ),
					'mo_smilepay_credit_installment' => __( '信用卡分期', 'mo-ectools' ),
					'mo_smilepay_atm'                => __( 'ATM 虛擬帳號', 'mo-ectools' ),
					'mo_smilepay_barcode'            => __( '四大超商條碼', 'mo-ectools' ),
					'mo_smilepay_ibon'               => __( 'ibon 代碼繳費', 'mo-ectools' ),
					'mo_smilepay_famiport'           => __( 'FamiPort 代碼繳費', 'mo-ectools' ),
					'mo_smilepay_unionpay'           => __( '銀聯線上刷卡', 'mo-ectools' ),
				],
				'desc'    => __( '勾選的付款方式才會出現在 WC 付款方式列表，未勾選不會出現（共 7 種，預設全部未勾選）。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_smilepay_methods_section',
			],
		];
	}
}
