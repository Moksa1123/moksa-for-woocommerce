<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Pchomepay\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '商家憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '向支付連申請取得 APP ID / Secret。支付連沒有公開測試帳號，沙箱憑證需另行申請。', 'mo-ectools' ),
				'id'    => 'moksafowo_pchomepay_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'moksafowo_pchomepay_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後所有交易走 sandbox-api.pchomepay.com.tw 沙箱不會真扣款。上線後請取消勾選。', 'mo-ectools' ),
			],
			[
				'title' => __( '測試 APP ID', 'mo-ectools' ),
				'id'    => 'moksafowo_pchomepay_sandbox_app_id',
				'type'  => 'text',
			],
			[
				'title' => __( '測試 Secret', 'mo-ectools' ),
				'id'    => 'moksafowo_pchomepay_sandbox_secret',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 APP ID', 'mo-ectools' ),
				'id'    => 'moksafowo_pchomepay_app_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 Secret', 'mo-ectools' ),
				'id'    => 'moksafowo_pchomepay_secret',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_pchomepay_section',
			],

			[
				'title' => __( '付款設定', 'mo-ectools' ),
				'type'  => 'title',
				'id'    => 'moksafowo_pchomepay_misc_section',
			],
			[
				'title'    => __( '信用卡分期期數', 'mo-ectools' ),
				'id'       => 'moksafowo_pchomepay_card_installment',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( '逗號分隔（例 1,3,6,12,18,24）。留空 = 不啟用分期，僅一次付清。', 'mo-ectools' ),
			],
			[
				'title'             => __( 'ATM 付款期限（天）', 'mo-ectools' ),
				'id'                => 'moksafowo_pchomepay_atm_expire_days',
				'type'              => 'number',
				'default'           => 5,
				'desc_tip'          => __( '顧客取得虛擬帳號後幾天內須完成付款（支付連限制 1-5 天）。', 'mo-ectools' ),
				'custom_attributes' => [ 'min' => 1, 'max' => 5, 'step' => 1 ],
			],
			[
				'title'             => __( '超商代碼付款期限（天）', 'mo-ectools' ),
				'id'                => 'moksafowo_pchomepay_bcode_expire_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( '顧客取得繳費代碼後幾天內須完成付款（支付連限制 1-7 天）。', 'mo-ectools' ),
				'custom_attributes' => [ 'min' => 1, 'max' => 7, 'step' => 1 ],
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'moksafowo_pchomepay_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查訂單異常時開啟。位置：WooCommerce → 狀態 → 日誌（來源 pchomepay-payment）。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_pchomepay_misc_section',
			],

			[
				'title' => __( '啟用的付款方式', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '勾選要在結帳頁顯示的支付連付款方式。', 'mo-ectools' ),
				'id'    => 'moksafowo_pchomepay_methods_section',
			],
			[
				'title'   => __( '付款方式', 'mo-ectools' ),
				'id'      => 'moksafowo_pchomepay_enabled_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'moksafowo_pchomepay_card'      => __( '信用卡（含分期）', 'mo-ectools' ),
					'moksafowo_pchomepay_pi'        => __( '拍錢包', 'mo-ectools' ),
					'moksafowo_pchomepay_atm'       => __( 'ATM 虛擬帳號', 'mo-ectools' ),
					'moksafowo_pchomepay_barcode'   => __( '超商代碼繳費', 'mo-ectools' ),
					'moksafowo_pchomepay_cvs711'    => __( '7-11 超商取貨付款', 'mo-ectools' ),
					'moksafowo_pchomepay_cvsfamily' => __( '全家超商取貨付款', 'mo-ectools' ),
					'moksafowo_pchomepay_cvshilife' => __( '萊爾富超商取貨付款', 'mo-ectools' ),
				],
				'desc'    => __( '勾選的付款方式才會出現在 WC 付款方式列表，未勾選不會出現（共 7 種，預設全部未勾選）。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_pchomepay_methods_section',
			],
		];
	}
}
