<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\ShoplinePayments\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		$callback_url = home_url( '/wc-api/mo_shopline_payments' );

		return [
			[
				'title' => __( '商家憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '向 Shopline Payments 申請取得 merchantId / apiKey / signKey。SLP 沒有公開測試帳號，沙箱憑證需另行向 SLP 整合團隊申請。apiKey 等同帳號密碼（出向無簽章），請妥善保管、人員異動後輪替。', 'mo-ectools' ),
				'id'    => 'moksafowo_shopline_payments_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'moksafowo_shopline_payments_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後所有交易走 api-sandbox.shoplinepayments.com 沙箱不會真扣款。沙箱與正式憑證、webhook 訂閱完全獨立。上線後請取消勾選。', 'mo-ectools' ),
			],
			[
				'title' => __( '測試 merchantId', 'mo-ectools' ),
				'id'    => 'moksafowo_shopline_payments_sandbox_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '測試 apiKey', 'mo-ectools' ),
				'id'    => 'moksafowo_shopline_payments_sandbox_api_key',
				'type'  => 'text',
			],
			[
				'title'    => __( '測試 signKey', 'mo-ectools' ),
				'id'       => 'moksafowo_shopline_payments_sandbox_sign_key',
				'type'     => 'text',
				'desc_tip' => __( '僅用於 webhook 簽章驗證，與 apiKey 分離。', 'mo-ectools' ),
			],
			[
				'title'    => __( '測試 platformId', 'mo-ectools' ),
				'id'       => 'moksafowo_shopline_payments_sandbox_platform_id',
				'type'     => 'text',
				'desc_tip' => __( '僅平台整合商需要，一般商家留空。', 'mo-ectools' ),
			],
			[
				'title' => __( '正式 merchantId', 'mo-ectools' ),
				'id'    => 'moksafowo_shopline_payments_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 apiKey', 'mo-ectools' ),
				'id'    => 'moksafowo_shopline_payments_api_key',
				'type'  => 'text',
			],
			[
				'title'    => __( '正式 signKey', 'mo-ectools' ),
				'id'       => 'moksafowo_shopline_payments_sign_key',
				'type'     => 'text',
				'desc_tip' => __( '僅用於 webhook 簽章驗證，與 apiKey 分離。', 'mo-ectools' ),
			],
			[
				'title'    => __( '正式 platformId', 'mo-ectools' ),
				'id'       => 'moksafowo_shopline_payments_platform_id',
				'type'     => 'text',
				'desc_tip' => __( '僅平台整合商需要，一般商家留空。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shopline_payments_section',
			],

			[
				'title' => __( 'Webhook 開通', 'mo-ectools' ),
				'type'  => 'title',
				/* translators: %s: callback URL */
				'desc'  => sprintf(
					/* translators: %s: webhook callback URL */
					__( 'Shopline Payments 沒有商家自助後台可自行設定 webhook，須將下列 callback 網址以 email 提供給 SLP 整合團隊開通（沙箱與正式環境須分別提交，不會自動同步）：<br><code>%s</code><br>未開通 webhook 時，付款結果不會自動回寫訂單狀態。', 'mo-ectools' ),
					esc_url( $callback_url )
				),
				'id'    => 'moksafowo_shopline_payments_webhook_section',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shopline_payments_webhook_section',
			],

			[
				'title' => __( '付款設定', 'mo-ectools' ),
				'type'  => 'title',
				'id'    => 'moksafowo_shopline_payments_misc_section',
			],
			[
				'title'   => __( '允許的付款方式', 'mo-ectools' ),
				'id'      => 'moksafowo_shopline_payments_payment_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'CreditCard'     => __( '信用卡', 'mo-ectools' ),
					'VirtualAccount' => __( '虛擬帳號（ATM）', 'mo-ectools' ),
					'ApplePay'       => __( 'Apple Pay', 'mo-ectools' ),
					'LinePay'        => __( 'LINE Pay', 'mo-ectools' ),
					'JKOPay'         => __( '街口支付', 'mo-ectools' ),
					'ChaileaseBNPL'  => __( '中租零卡分期', 'mo-ectools' ),
				],
				'desc'    => __( '送至 SLP 託管結帳頁時限定可用的付款方式。留空 = 不限制（依 SLP 商家設定全開）。實際代碼以 SLP 開通的為準。', 'mo-ectools' ),
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'moksafowo_shopline_payments_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查訂單異常時開啟。位置：WooCommerce → 狀態 → 日誌（來源 shopline-payments）。apiKey / signKey 不會寫入日誌。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shopline_payments_misc_section',
			],
		];
	}
}
