<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Settings;

use Moksafowo\Modules\EcpayShipping\Api\Helper;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '基本設定', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '物流憑證設定完，到「WooCommerce → 設定 → 運送方式」啟用配送區域。', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_shipping_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( '上線前用，勾選後，所有物流單走測試環境不會真出貨。上線後請取消勾選。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_shipping_section',
			],

			[
				'title' => __( '一般寄件帳號（C2C）', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '個人或店家少量寄件，從 7-11 / 全家 / 萊爾富 / OK 超商店到店取貨。<strong>免月租</strong>，最常見。沒申請就跳過。', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_c2c_section',
			],
			[
				'title'   => __( '測試 MerchantID', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_shipping_c2c_sandbox_merchant_id',
				'type'    => 'text',
				'default' => Helper::SANDBOX_C2C_MERCHANT_ID,
			],
			[
				'title'   => __( '測試 HashKey', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_shipping_c2c_sandbox_hash_key',
				'type'    => 'text',
				'default' => Helper::SANDBOX_C2C_HASH_KEY,
			],
			[
				'title'   => __( '測試 HashIV', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_shipping_c2c_sandbox_hash_iv',
				'type'    => 'text',
				'default' => Helper::SANDBOX_C2C_HASH_IV,
			],
			[
				'title' => __( '正式 MerchantID', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_c2c_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashKey', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_c2c_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashIV', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_c2c_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_shipping_c2c_section',
			],

			[
				'title' => __( '大宗寄件帳號（B2C）', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '企業大量寄件 — 7-11 / 全家 / 萊爾富 超商大宗，以及黑貓宅配 / 中華郵政 / 嘉里大榮。<strong>需簽月租合約</strong>。沒申請就跳過。', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_b2c_section',
			],
			[
				'title'   => __( '測試 MerchantID', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_shipping_b2c_sandbox_merchant_id',
				'type'    => 'text',
				'default' => Helper::SANDBOX_B2C_MERCHANT_ID,
			],
			[
				'title'   => __( '測試 HashKey', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_shipping_b2c_sandbox_hash_key',
				'type'    => 'text',
				'default' => Helper::SANDBOX_B2C_HASH_KEY,
			],
			[
				'title'   => __( '測試 HashIV', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_shipping_b2c_sandbox_hash_iv',
				'type'    => 'text',
				'default' => Helper::SANDBOX_B2C_HASH_IV,
			],
			[
				'title' => __( '正式 MerchantID', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_b2c_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashKey', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_b2c_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashIV', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_b2c_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_shipping_b2c_section',
			],

			[
				'title' => __( '寄件人資料', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '超商取貨標籤上的寄件人資訊，宅配時供物流商聯絡。', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_sender_section',
			],
			[
				'title' => __( '姓名', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_sender_name',
				'type'  => 'text',
				'desc'  => __( '中文 1-10 字 / 英文 1-20 字。', 'mo-ectools' ),
			],
			[
				'title' => __( '電話', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_sender_phone',
				'type'  => 'text',
				'desc'  => __( '室內電話。', 'mo-ectools' ),
			],
			[
				'title' => __( '手機', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_sender_cellphone',
				'type'  => 'text',
				'desc'  => __( '宅配 / 黑貓必填。', 'mo-ectools' ),
			],
			[
				'title' => __( 'Email', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_sender_email',
				'type'  => 'email',
			],
			[
				'title' => __( '寄件地址', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_shipping_sender_address',
				'type'  => 'textarea',
				'desc'  => __( '宅配用，超商寄件不需要。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_shipping_sender_section',
			],

			[
				'title' => __( '其他', 'mo-ectools' ),
				'type'  => 'title',
				'id'    => 'moksafowo_ecpay_shipping_misc_section',
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_shipping_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查物流單異常時開啟。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_shipping_misc_section',
			],
		];
	}
}
