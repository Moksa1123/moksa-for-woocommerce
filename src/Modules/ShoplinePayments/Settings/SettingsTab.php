<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		$callback_url = home_url( '/wc-api/moksafowo_shopline_payments' );

		return [
			[
				'title' => __( '商家憑證', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( '向 Shopline Payments 申請取得 merchantId / apiKey / signKey。沒有公開測試帳號，測試憑證需另行向 Shopline Payments 申請。apiKey 請妥善保管。', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shopline_payments_section',
			],
			[
				'title'   => __( '啟用測試模式', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_shopline_payments_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後所有交易走測試環境不會真扣款。測試與正式憑證相互獨立。上線後請取消勾選。', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( '測試 merchantId', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shopline_payments_sandbox_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '測試 apiKey', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shopline_payments_sandbox_api_key',
				'type'  => 'text',
			],
			[
				'title'    => __( '測試 signKey', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_shopline_payments_sandbox_sign_key',
				'type'     => 'text',
				'desc_tip' => __( '用於驗證付款通知的合法性。', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( '測試 platformId', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_shopline_payments_sandbox_platform_id',
				'type'     => 'text',
				'desc_tip' => __( '僅平台整合商需要，一般商家留空。', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( '正式 merchantId', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shopline_payments_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 apiKey', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shopline_payments_api_key',
				'type'  => 'text',
			],
			[
				'title'    => __( '正式 signKey', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_shopline_payments_sign_key',
				'type'     => 'text',
				'desc_tip' => __( '用於驗證付款通知的合法性。', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( '正式 platformId', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_shopline_payments_platform_id',
				'type'     => 'text',
				'desc_tip' => __( '僅平台整合商需要，一般商家留空。', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shopline_payments_section',
			],

			[
				'title' => __( '付款通知設定', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				/* translators: %s: callback URL */
				'desc'  => sprintf(
					/* translators: %s: notification URL */
					__( 'Shopline Payments 需手動開通付款通知，請將下列網址提供給 Shopline Payments 設定（測試與正式環境須分別提交）：<br><code>%s</code><br>未設定時，付款結果不會自動更新訂單狀態。', 'moksa-for-woocommerce' ),
					esc_url( $callback_url )
				),
				'id'    => 'moksafowo_shopline_payments_webhook_section',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shopline_payments_webhook_section',
			],

			[
				'title' => __( '付款設定', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_shopline_payments_misc_section',
			],
			[
				'title'   => __( '允許的付款方式', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_shopline_payments_payment_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'CreditCard'     => __( '信用卡', 'moksa-for-woocommerce' ),
					'VirtualAccount' => __( '虛擬帳號（ATM）', 'moksa-for-woocommerce' ),
					'ApplePay'       => __( 'Apple Pay', 'moksa-for-woocommerce' ),
					'LinePay'        => __( 'LINE Pay', 'moksa-for-woocommerce' ),
					'JKOPay'         => __( '街口支付', 'moksa-for-woocommerce' ),
					'ChaileaseBNPL'  => __( '中租零卡分期', 'moksa-for-woocommerce' ),
				],
				'desc'    => __( '轉跳到付款頁後限定可用的付款方式。留空 = 不限制。實際項目以 Shopline Payments 開通的為準。', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( '偵錯日誌', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_shopline_payments_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查訂單異常時開啟。位置：WooCommerce → 狀態 → 日誌。', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shopline_payments_misc_section',
			],
		];
	}
}
