<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '基本設定', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( '物流憑證設定完，到「WooCommerce → 設定 → 運送方式」啟用配送區域。', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_section',
			],
			[
				'title'   => __( '啟用測試模式', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_smilepay_shipping_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後走測試環境，不會真出貨。上線前測試用，上線後請取消勾選。', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( '超商服務類型', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_smilepay_shipping_cvs_service_type',
				'type'     => 'select',
				'default'  => 'C2C',
				'options'  => [
					'C2C' => __( '個人帳戶（超商店到店，免月租，最常見）', 'moksa-for-woocommerce' ),
					'B2C' => __( '大宗合約（需另跟 SmilePay 簽月租）', 'moksa-for-woocommerce' ),
				],
				'desc'     => __( '需依 SmilePay 後台開通的帳號類型選擇。', 'moksa-for-woocommerce' ),
				'desc_tip' => true,
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_shipping_section',
			],

			[
				'title' => __( '商家憑證', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( '從 SmilePay 後台「會員專區 → 系統識別資料」取得 Dcvc / Rvg2c / Verify_key / 商家編號 (SmseID)。', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_creds_section',
			],
			[
				'title' => __( 'Dcvc — 商家代號', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_dcvc',
				'type'  => 'text',
			],
			[
				'title' => __( 'Rvg2c — 檢查碼', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_rvg2c',
				'type'  => 'text',
			],
			[
				'title' => __( 'Verify_key — 驗證金鑰', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_verify_key',
				'type'  => 'text',
				'desc'  => __( '收到物流通知時驗章用，防止偽造。', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'SmseID — 商家編號', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_smseid',
				'type'  => 'text',
				'desc'  => __( '部分物流服務需要。', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_shipping_creds_section',
			],

			[
				'title' => __( '寄件人資料', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( '超商大宗寄件與黑貓宅配必填。', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_section',
			],
			[
				'title' => __( '寄件人姓名', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_name',
				'type'  => 'text',
				'desc'  => __( '中文 1-5 字 / 英文 1-10 字。', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( '寄件人手機', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_phone',
				'type'  => 'text',
				'desc'  => __( '10 碼 09 開頭。', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( '寄件人 Email', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_email',
				'type'  => 'email',
			],
			[
				'title' => __( '寄件地址', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_address',
				'type'  => 'textarea',
				'desc'  => __( '黑貓宅配寄件人地址。', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_shipping_sender_section',
			],

			[
				'title' => __( '其他', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_smilepay_shipping_misc_section',
			],
			[
				'title'   => __( 'Debug 日誌', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_smilepay_shipping_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查物流單異常時開啟。位置：WooCommerce → 狀態 → 日誌。', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_shipping_misc_section',
			],
		];
	}
}
