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
				'desc'  => __( 'ezPay 電子發票串接。商家須自行至 cinv.ezpay.com.tw（沙箱）／ inv.ezpay.com.tw（正式）申請會員、開立商店、取得 MerchantID + HashKey + HashIV。', 'mo-ectools' ),
				'id'    => 'mo_ezpay_invoice_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'mo_ezpay_invoice_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後走 cinv.ezpay.com.tw 測試環境。預設關閉（對齊 RY / ezPay 官方建議）— 開發階段才開啟。', 'mo-ectools' ),
			],
			[
				'title'   => __( '訂單編號前綴', 'mo-ectools' ),
				'id'      => 'mo_ezpay_invoice_order_prefix',
				'type'    => 'text',
				'default' => '',
				'desc'    => __( '商家自定的訂單前綴（限英數，最多 5 字元）。送進 ezPay 的 MerchantOrderNo 會以此開頭。留空即無前綴。', 'mo-ectools' ),
				'desc_tip' => true,
				'custom_attributes' => [ 'pattern' => '[A-Za-z0-9]{0,5}', 'maxlength' => 5 ],
			],
			[
				'title'   => __( '開立時機', 'mo-ectools' ),
				'id'      => 'mo_ezpay_invoice_issue_when',
				'type'    => 'select',
				'default' => 'paid',
				'options' => [
					'paid'      => __( '付款完成 — 訂單一變 processing / on-hold 已付狀態就立即開立', 'mo-ectools' ),
					'completed' => __( '訂單完成 — 訂單變 completed 才開立（出貨後）', 'mo-ectools' ),
					'manual'    => __( '手動 — 商家自己在訂單頁按「開立發票」按鈕', 'mo-ectools' ),
				],
			],
			[
				'title'   => __( '訂單退款 / 取消時自動作廢發票', 'mo-ectools' ),
				'id'      => 'mo_ezpay_invoice_auto_cancel',
				'type'    => 'select',
				'default' => 'manual',
				'options' => [
					'manual'      => __( '手動 — 商家自己進訂單編輯頁按「作廢發票」', 'mo-ectools' ),
					'auto_cancel' => __( '自動 — 訂單變取消 / 退款 / 失敗時 2 分鐘後自動作廢', 'mo-ectools' ),
				],
				'desc'    => __( '預設「手動」最保守。改成「自動」後，訂單變取消 / 退款 / 失敗時會在 2 分鐘後自動觸發作廢（buffer 期間切回正常狀態可救回）。', 'mo-ectools' ),
			],
			[
				'title'   => __( '結帳允許捐贈', 'mo-ectools' ),
				'id'      => 'mo_ezpay_invoice_allow_donate',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( '結帳允許統編（公司用三聯式）', 'mo-ectools' ),
				'id'      => 'mo_ezpay_invoice_allow_b2b',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'mo_ezpay_invoice_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查 API 異常時開啟。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_ezpay_invoice_section',
			],

			[
				'title' => __( '測試環境憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: ezPay sandbox URL */
					__( '從 %s 申請取得。ezPay 沒有公開共用測試帳號，每個商家自家測試環境憑證不同。', 'mo-ectools' ),
					'<a href="https://cinv.ezpay.com.tw" target="_blank">cinv.ezpay.com.tw</a>'
				),
				'id'    => 'mo_ezpay_invoice_sandbox_section',
			],
			[
				'title' => __( '測試 MerchantID', 'mo-ectools' ),
				'id'    => 'mo_ezpay_invoice_sandbox_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '測試 HashKey', 'mo-ectools' ),
				'id'    => 'mo_ezpay_invoice_sandbox_hash_key',
				'type'  => 'text',
				'desc'  => __( 'AES-256-CBC 加密金鑰，32 字元。', 'mo-ectools' ),
			],
			[
				'title' => __( '測試 HashIV', 'mo-ectools' ),
				'id'    => 'mo_ezpay_invoice_sandbox_hash_iv',
				'type'  => 'text',
				'desc'  => __( 'AES-256-CBC IV，16 字元。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_ezpay_invoice_sandbox_section',
			],

			[
				'title' => __( '正式環境憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '從 inv.ezpay.com.tw 申請取得。需 ezPay 審核啟用後才能用。', 'mo-ectools' ),
				'id'    => 'mo_ezpay_invoice_prod_section',
			],
			[
				'title' => __( '正式 MerchantID', 'mo-ectools' ),
				'id'    => 'mo_ezpay_invoice_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashKey', 'mo-ectools' ),
				'id'    => 'mo_ezpay_invoice_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashIV', 'mo-ectools' ),
				'id'    => 'mo_ezpay_invoice_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_ezpay_invoice_prod_section',
			],
		];
	}
}
