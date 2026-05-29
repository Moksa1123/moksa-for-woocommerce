<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Ecpay\Settings;

use MoksaWeb\Mowc\Modules\Ecpay\Api\Helper;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '商家憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '從綠界後台「廠商專區 → 系統識別資料」複製過來。測試模式可直接用預設的官方公開測試帳號。', 'mo-ectools' ),
				'id'    => 'mo_ecpay_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'mo_ecpay_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( '上線前用，勾選後，所有交易走測試環境不會真扣款。上線後請取消勾選。', 'mo-ectools' ),
			],
			[
				'title'   => __( '測試 MerchantID', 'mo-ectools' ),
				'id'      => 'mo_ecpay_sandbox_merchant_id',
				'type'    => 'text',
				'default' => Helper::SANDBOX_MERCHANT_ID,
			],
			[
				'title'   => __( '測試 HashKey', 'mo-ectools' ),
				'id'      => 'mo_ecpay_sandbox_hash_key',
				'type'    => 'text',
				'default' => Helper::SANDBOX_HASH_KEY,
			],
			[
				'title'   => __( '測試 HashIV', 'mo-ectools' ),
				'id'      => 'mo_ecpay_sandbox_hash_iv',
				'type'    => 'text',
				'default' => Helper::SANDBOX_HASH_IV,
			],
			[
				'title' => __( '正式 MerchantID', 'mo-ectools' ),
				'id'    => 'mo_ecpay_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashKey', 'mo-ectools' ),
				'id'    => 'mo_ecpay_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashIV', 'mo-ectools' ),
				'id'    => 'mo_ecpay_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_ecpay_section',
			],

			[
				'title' => __( '訂單與商品', 'mo-ectools' ),
				'type'  => 'title',
				'id'    => 'mo_ecpay_misc_section',
			],
			[
				'title'   => __( '訂單編號前綴', 'mo-ectools' ),
				'id'      => 'mo_ecpay_order_prefix',
				'type'    => 'text',
				'default' => '',
				'desc'    => __( '最多 5 字元，限英數（對齊 ECPay 官方限制）。留空即無前綴，MerchantTradeNo 直接從訂單 ID 開始。', 'mo-ectools' ),
				'desc_tip' => true,
				'custom_attributes' => [ 'pattern' => '[A-Za-z0-9]{0,5}', 'maxlength' => 5 ],
			],
			[
				'title'    => __( '付款頁顯示的商品名稱', 'mo-ectools' ),
				'id'       => 'mo_ecpay_payment_item_name',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( '留空 = 用訂單第一個商品名稱（建議）。填固定文字（例如「網路商品一批」）所有訂單都顯示同樣字。', 'mo-ectools' ),
			],
			[
				'title'             => __( 'ATM 付款期限（天）', 'mo-ectools' ),
				'id'                => 'mo_ecpay_atm_expire_days',
				'type'              => 'number',
				'default'           => 3,
				'desc_tip'          => __( '顧客選 ATM 後，幾天內沒付款訂單就過期（綠界限制 1-60 天）。', 'mo-ectools' ),
				'custom_attributes' => [ 'min' => 1, 'max' => 60, 'step' => 1 ],
			],
			[
				'title'             => __( '超商代碼付款期限（天）', 'mo-ectools' ),
				'id'                => 'mo_ecpay_cvs_expire_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( '顧客拿代碼到超商繳費的期限（綠界限制 1-7 天）。', 'mo-ectools' ),
				'custom_attributes' => [ 'min' => 1, 'max' => 7, 'step' => 1 ],
			],
			[
				'title'             => __( '超商條碼付款期限（天）', 'mo-ectools' ),
				'id'                => 'mo_ecpay_barcode_expire_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( '顧客拿條碼到超商繳費的期限（綠界限制 1-7 天）。', 'mo-ectools' ),
				'custom_attributes' => [ 'min' => 1, 'max' => 7, 'step' => 1 ],
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'mo_ecpay_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查訂單異常時開啟。位置：WooCommerce → 狀態 → 日誌。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_ecpay_misc_section',
			],

			[
				'title' => __( '結帳頁呈現方式', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '決定顧客看到「一個按鈕跳轉綠界選付款方式」還是「每個付款方式各一個按鈕」。', 'mo-ectools' ),
				'id'    => 'mo_ecpay_display_setting',
			],
			[
				'title'   => __( '顯示方式', 'mo-ectools' ),
				'id'      => 'mo_ecpay_display_mode',
				'type'    => 'select',
				'css'     => 'width: 400px;',
				'options' => [
					'multi'  => __( '分開顯示（每個付款方式一個按鈕，建議）', 'mo-ectools' ),
					'single' => __( '合併顯示（一個「綠界」按鈕跳轉收銀台再選）', 'mo-ectools' ),
				],
				'default' => 'multi',
			],
			[
				'title'   => __( '啟用的付款方式', 'mo-ectools' ),
				'id'      => 'mo_ecpay_enabled_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'mo_ecpay_credit'    => __( '信用卡（一次付清）', 'mo-ectools' ),
					'mo_ecpay_credit_3'  => __( '信用卡分期 3 期', 'mo-ectools' ),
					'mo_ecpay_credit_6'  => __( '信用卡分期 6 期', 'mo-ectools' ),
					'mo_ecpay_credit_12' => __( '信用卡分期 12 期', 'mo-ectools' ),
					'mo_ecpay_credit_18' => __( '信用卡分期 18 期', 'mo-ectools' ),
					'mo_ecpay_credit_24' => __( '信用卡分期 24 期', 'mo-ectools' ),
					'mo_ecpay_atm'       => __( 'ATM 虛擬帳號', 'mo-ectools' ),
					'mo_ecpay_cvs'       => __( '超商代碼', 'mo-ectools' ),
					'mo_ecpay_barcode'   => __( '超商條碼', 'mo-ectools' ),
					'mo_ecpay_webatm'    => __( '網路 ATM', 'mo-ectools' ),
					'mo_ecpay_applepay'  => __( 'Apple Pay', 'mo-ectools' ),
					'mo_ecpay_twqr'      => __( '行動支付（TWQR Pay）', 'mo-ectools' ),
					'mo_ecpay_bnpl'      => __( '無卡分期', 'mo-ectools' ),
					'mo_ecpay_weixin'    => __( '微信支付', 'mo-ectools' ),
					'mo_ecpay_jkopay'    => __( '街口支付', 'mo-ectools' ),
					'mo_ecpay_ipass'     => __( '一卡通 iPASS', 'mo-ectools' ),
				],
				'desc'    => __( '勾選的付款方式才會出現在 WC 付款方式列表，未勾選不會出現（共 16 種，預設全部未勾選）。「合併顯示」模式下此欄位無效。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_ecpay_display_setting',
			],
		];
	}
}
