<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '商家憑證', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( '從藍新後台「商店資料設定」複製過來。藍新沒有公開測試帳號，需自行申請測試環境。', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_section',
			],
			[
				'title'   => __( '啟用測試模式', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_newebpay_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '上線前用，勾選後所有交易走測試環境不會真扣款。上線後請取消勾選。', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( '測試 MerchantID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_sandbox_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '測試 HashKey', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_sandbox_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( '測試 HashIV', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_sandbox_hash_iv',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 MerchantID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashKey', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashIV', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_section',
			],

			[
				'title' => __( '訂單與商品', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_newebpay_misc_section',
			],
			[
				'title'             => __( '訂單編號前綴', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_newebpay_order_prefix',
				'type'              => 'text',
				'default'           => '',
				'desc'              => __( '最多 5 字元，限英數。留空則訂單編號直接從訂單號開始。', 'moksa-for-woocommerce' ),
				'desc_tip'          => true,
				'custom_attributes' => [
					'pattern'   => '[A-Za-z0-9]{0,5}',
					'maxlength' => 5,
				],
			],
			[
				'title'    => __( '付款頁顯示的商品名稱', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_newebpay_payment_item_name',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( '留空 = 用訂單第一個商品名稱（建議）。填固定文字（例如「網路商品一批」）所有訂單都顯示同樣字。', 'moksa-for-woocommerce' ),
			],
			[
				'title'             => __( 'ATM 付款期限（天）', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_newebpay_atm_expire_days',
				'type'              => 'number',
				'default'           => 3,
				'desc_tip'          => __( '顧客選 ATM 後，幾天內沒付款訂單就過期。藍新最多 60 天。', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 60,
					'step' => 1,
				],
			],
			[
				'title'             => __( '超商代碼付款期限（天）', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_newebpay_cvs_expire_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( '顧客拿代碼到超商繳費的期限（藍新限制 1-30 天）。', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 30,
					'step' => 1,
				],
			],
			[
				'title'             => __( '超商條碼付款期限（天）', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_newebpay_barcode_expire_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( '顧客拿條碼到超商繳費的期限（藍新限制 1-30 天）。', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 30,
					'step' => 1,
				],
			],
			[
				'title'   => __( '偵錯日誌', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_newebpay_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查訂單異常時開啟。位置：WooCommerce → 狀態 → 日誌。', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_misc_section',
			],

			[
				'title' => __( '結帳頁呈現方式', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( '決定顧客看到「一個按鈕跳轉藍新選付款方式」還是「每個付款方式各一個按鈕」。', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_display_setting',
			],
			[
				'title'   => __( '顯示方式', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_newebpay_display_mode',
				'type'    => 'select',
				'css'     => 'width: 400px;',
				'options' => [
					'multi'  => __( '分開顯示（每個付款方式一個按鈕，建議）', 'moksa-for-woocommerce' ),
					'single' => __( '合併顯示（一個「藍新」按鈕跳轉收銀台再選）', 'moksa-for-woocommerce' ),
				],
				'default' => 'multi',
			],
			[
				'title'   => __( '啟用的付款方式', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_newebpay_enabled_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'moksafowo_newebpay_credit'     => __( '信用卡（一次付清）', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_credit_installment' => __( '信用卡分期（3/6/12/18/24 期）', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_atm'        => __( 'ATM 虛擬帳號', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_webatm'     => __( 'WebATM 網路 ATM', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_cvs'        => __( '超商代碼', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_barcode'    => __( '超商條碼', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_applepay'   => __( 'Apple Pay', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_googlepay'  => __( 'Google Pay', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_samsungpay' => __( 'Samsung Pay', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_linepay'    => __( 'LINE Pay（透過藍新）', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_esunwallet' => __( '玉山 Wallet', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_taiwanpay'  => __( '台灣 Pay', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_twqr'       => __( 'TWQR 行動支付', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_alipay'     => __( '支付寶', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_wechatpay'  => __( '微信支付', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_aftee'      => __( 'AFTEE 無卡分期', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_unionpay'   => __( '銀聯卡', 'moksa-for-woocommerce' ),
				],
				'desc'    => __( '勾選的付款方式才會出現在結帳頁，未勾選不會出現。「合併顯示」模式下此欄位無效。AFTEE / 銀聯 / 跨境支付需先到藍新後台開通才會在收銀台出現。', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_display_setting',
			],
		];
	}
}
