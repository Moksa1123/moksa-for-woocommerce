<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '基本設定', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '物流憑證設定完，到「WooCommerce → 設定 → 運送方式」啟用配送區域。', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_shipping_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'moksafowo_smilepay_shipping_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後走測試環境，不會真出貨。上線前測試用，上線後請取消勾選。', 'mo-ectools' ),
			],
			[
				'title'    => __( '超商服務類型', 'mo-ectools' ),
				'id'       => 'moksafowo_smilepay_shipping_cvs_service_type',
				'type'     => 'select',
				'default'  => 'C2C',
				'options'  => [
					'C2C' => __( '個人帳戶（超商店到店，免月租，最常見）', 'mo-ectools' ),
					'B2C' => __( '大宗合約（需另跟 SmilePay 簽月租）', 'mo-ectools' ),
				],
				'desc'     => __( '需依 SmilePay 後台開通的帳號類型選擇。', 'mo-ectools' ),
				'desc_tip' => true,
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_shipping_section',
			],

			[
				'title' => __( '商家憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '從 SmilePay 後台「會員專區 → 系統識別資料」取得 Dcvc / Rvg2c / Verify_key / 商家編號 (SmseID)。', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_shipping_creds_section',
			],
			[
				'title' => __( 'Dcvc — 商家代號', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_shipping_dcvc',
				'type'  => 'text',
			],
			[
				'title' => __( 'Rvg2c — 檢查碼', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_shipping_rvg2c',
				'type'  => 'text',
			],
			[
				'title' => __( 'Verify_key — 驗證金鑰', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_shipping_verify_key',
				'type'  => 'text',
				'desc'  => __( '收到物流通知時驗章用，防止偽造。', 'mo-ectools' ),
			],
			[
				'title' => __( 'SmseID — 商家編號', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_shipping_smseid',
				'type'  => 'text',
				'desc'  => __( '部分物流服務需要。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_shipping_creds_section',
			],

			[
				'title' => __( '寄件人資料', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '超商大宗寄件與黑貓宅配必填。', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_section',
			],
			[
				'title' => __( '寄件人姓名', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_name',
				'type'  => 'text',
				'desc'  => __( '中文 1-5 字 / 英文 1-10 字。', 'mo-ectools' ),
			],
			[
				'title' => __( '寄件人手機', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_phone',
				'type'  => 'text',
				'desc'  => __( '10 碼 09 開頭。', 'mo-ectools' ),
			],
			[
				'title' => __( '寄件人 Email', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_email',
				'type'  => 'email',
			],
			[
				'title' => __( '寄件地址', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_address',
				'type'  => 'textarea',
				'desc'  => __( '黑貓宅配寄件人地址。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_shipping_sender_section',
			],

			[
				'title' => __( '其他', 'mo-ectools' ),
				'type'  => 'title',
				'id'    => 'moksafowo_smilepay_shipping_misc_section',
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'moksafowo_smilepay_shipping_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查物流單異常時開啟。位置：WooCommerce → 狀態 → 日誌。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_shipping_misc_section',
			],
		];
	}
}
