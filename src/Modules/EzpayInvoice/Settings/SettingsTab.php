<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EzpayInvoice\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '基本設定', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( 'ezPay 電子發票串接。請先至 ezPay 後台申請會員、開立商店，取得商店憑證後填入下方。', 'mo-ectools' ),
				'id'    => 'moksafowo_ezpay_invoice_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'moksafowo_ezpay_invoice_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後走測試環境，不會真正開立發票。上線後請取消勾選。', 'mo-ectools' ),
			],
			[
				'title'             => __( '訂單編號前綴', 'mo-ectools' ),
				'id'                => 'moksafowo_ezpay_invoice_order_prefix',
				'type'              => 'text',
				'default'           => '',
				'desc'              => __( '送至 ezPay 的訂單編號前綴（限英數，最多 5 字元）。留空即無前綴。', 'mo-ectools' ),
				'desc_tip'          => true,
				'custom_attributes' => [
					'pattern'   => '[A-Za-z0-9]{0,5}',
					'maxlength' => 5,
				],
			],
			[
				'title'   => __( '開立時機', 'mo-ectools' ),
				'id'      => 'moksafowo_ezpay_invoice_issue_when',
				'type'    => 'select',
				'default' => 'paid',
				'options' => [
					'paid'      => __( '付款完成立即開立', 'mo-ectools' ),
					'completed' => __( '訂單完成（出貨後）才開立', 'mo-ectools' ),
					'manual'    => __( '手動 — 商家自己在訂單頁按「開立發票」按鈕', 'mo-ectools' ),
				],
			],
			[
				'title'   => __( '訂單退款 / 取消時自動作廢發票', 'mo-ectools' ),
				'id'      => 'moksafowo_ezpay_invoice_auto_cancel',
				'type'    => 'select',
				'default' => 'manual',
				'options' => [
					'manual'      => __( '手動 — 商家自己進訂單編輯頁按「作廢發票」', 'mo-ectools' ),
					'auto_cancel' => __( '自動 — 訂單變取消 / 退款 / 失敗時 2 分鐘後自動作廢', 'mo-ectools' ),
				],
				'desc'    => __( '改成「自動」後，訂單取消 / 退款 / 失敗時會自動作廢該訂單已開立的發票，避免忘記作廢造成稅務問題。', 'mo-ectools' ),
			],
			[
				'title'   => __( '結帳允許捐贈', 'mo-ectools' ),
				'id'      => 'moksafowo_ezpay_invoice_allow_donate',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( '結帳允許統編（公司用三聯式）', 'mo-ectools' ),
				'id'      => 'moksafowo_ezpay_invoice_allow_b2b',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'         => __( '開放的個人載具', 'mo-ectools' ),
				'desc'          => __( 'ezPay 平台會員載具', 'mo-ectools' ),
				'id'            => 'moksafowo_ezpay_invoice_channel_member',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			],
			[
				'desc'          => __( '手機條碼', 'mo-ectools' ),
				'id'            => 'moksafowo_ezpay_invoice_channel_mobile',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '自然人憑證', 'mo-ectools' ),
				'id'            => 'moksafowo_ezpay_invoice_channel_cert',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '紙本發票', 'mo-ectools' ),
				'id'            => 'moksafowo_ezpay_invoice_channel_paper',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'end',
				'desc_tip'      => __( '勾選要在結帳頁與後台手動開立提供的個人載具。至少留一項；全不勾會自動保留紙本。', 'mo-ectools' ),
			],
			[
				'title'    => __( '個人預設載具', 'mo-ectools' ),
				'id'       => 'moksafowo_ezpay_invoice_default_carrier',
				'type'     => 'select',
				'options'  => [
					'member' => __( 'ezPay 平台會員載具', 'mo-ectools' ),
					'mobile' => __( '手機條碼', 'mo-ectools' ),
					'cert'   => __( '自然人憑證', 'mo-ectools' ),
					'paper'  => __( '紙本發票', 'mo-ectools' ),
				],
				'default'  => 'member',
				'desc_tip' => __( '結帳頁與後台手動開立的預設選取載具（須是上方有開放的）。', 'mo-ectools' ),
			],
			[
				'title'       => __( '捐贈單位', 'mo-ectools' ),
				'id'          => 'moksafowo_ezpay_invoice_donate_orgs',
				'type'        => 'textarea',
				'default'     => '',
				'css'         => 'min-height:90px;width:100%;',
				'placeholder' => '伊甸社會福利基金會|25885',
				'desc_tip'    => __( '輸入捐贈機構（每行一筆），格式為：社福團體名稱|愛心碼（例：伊甸社會福利基金會|25885）。填了之後顧客結帳與後台手動開立改用下拉選單挑單位（看得到名稱）；留空則由顧客自行輸入愛心碼。', 'mo-ectools' ),
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'moksafowo_ezpay_invoice_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查發票異常時開啟。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ezpay_invoice_section',
			],

			[
				'title' => __( '測試環境憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: ezPay sandbox URL */
					__( '從 %s 申請取得。ezPay 沒有公開共用測試帳號，每個商家自家測試環境憑證不同。', 'mo-ectools' ),
					'<a href="https://cinv.ezpay.com.tw" target="_blank">cinv.ezpay.com.tw</a>'
				),
				'id'    => 'moksafowo_ezpay_invoice_sandbox_section',
			],
			[
				'title' => __( '測試 MerchantID', 'mo-ectools' ),
				'id'    => 'moksafowo_ezpay_invoice_sandbox_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '測試 HashKey', 'mo-ectools' ),
				'id'    => 'moksafowo_ezpay_invoice_sandbox_hash_key',
				'type'  => 'text',
				'desc'  => __( '32 字元。', 'mo-ectools' ),
			],
			[
				'title' => __( '測試 HashIV', 'mo-ectools' ),
				'id'    => 'moksafowo_ezpay_invoice_sandbox_hash_iv',
				'type'  => 'text',
				'desc'  => __( '16 字元。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ezpay_invoice_sandbox_section',
			],

			[
				'title' => __( '正式環境憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '從 inv.ezpay.com.tw 申請取得。需 ezPay 審核啟用後才能用。', 'mo-ectools' ),
				'id'    => 'moksafowo_ezpay_invoice_prod_section',
			],
			[
				'title' => __( '正式 MerchantID', 'mo-ectools' ),
				'id'    => 'moksafowo_ezpay_invoice_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashKey', 'mo-ectools' ),
				'id'    => 'moksafowo_ezpay_invoice_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashIV', 'mo-ectools' ),
				'id'    => 'moksafowo_ezpay_invoice_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ezpay_invoice_prod_section',
			],
		];
	}
}
