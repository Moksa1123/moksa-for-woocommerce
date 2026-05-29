<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\AmegoInvoice\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '基本設定', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: Amego docs link */
					__( 'AMEGO 光貿電子發票串接（API 文件 %s）。Amego 測試與正式同一個 API 網址，靠統編 + App Key 區分。Amego 提供「公開測試帳號」（統編 12345678 / App Key sHeq7t8G1wiQvhAuIM27），勾選測試模式後即可直接試開立。', 'mo-ectools' ),
					'<a href="https://invoice.amego.tw/api_doc/" target="_blank">invoice.amego.tw/api_doc/</a>'
				),
				'id'    => 'mo_amego_invoice_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'mo_amego_invoice_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後使用 Amego 公開測試憑證（除非自設下方測試環境憑證）。', 'mo-ectools' ),
			],
			[
				'title'   => __( 'OrderId 前綴', 'mo-ectools' ),
				'id'      => 'mo_amego_invoice_order_prefix',
				'type'    => 'text',
				'default' => '',
				'desc'    => __( '送 API 的 OrderId 前綴（限英數，最多 5 字元）。留空無前綴。', 'mo-ectools' ),
				'desc_tip' => true,
				'custom_attributes' => [ 'pattern' => '[A-Za-z0-9]{0,5}', 'maxlength' => 5 ],
			],
			[
				'title'   => __( '開立時機', 'mo-ectools' ),
				'id'      => 'mo_amego_invoice_issue_when',
				'type'    => 'select',
				'default' => 'paid',
				'options' => [
					'paid'      => __( '付款完成 — 訂單變 processing 立即開立', 'mo-ectools' ),
					'completed' => __( '訂單完成 — 訂單變 completed 才開立', 'mo-ectools' ),
					'manual'    => __( '手動 — 商家在訂單頁按按鈕開立', 'mo-ectools' ),
				],
			],
			[
				'title'   => __( '訂單退款 / 取消時自動作廢發票', 'mo-ectools' ),
				'id'      => 'mo_amego_invoice_auto_cancel',
				'type'    => 'select',
				'default' => 'manual',
				'options' => [
					'manual'      => __( '手動', 'mo-ectools' ),
					'auto_cancel' => __( '自動 — 訂單變取消 / 退款 / 失敗時 2 分鐘後自動作廢', 'mo-ectools' ),
				],
			],
			[
				'title'   => __( '結帳允許捐贈', 'mo-ectools' ),
				'id'      => 'mo_amego_invoice_allow_donate',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( '結帳允許統編（公司用三聯式）', 'mo-ectools' ),
				'id'      => 'mo_amego_invoice_allow_b2b',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'mo_amego_invoice_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_amego_invoice_section',
			],

			[
				'title' => __( '測試環境憑證（選填）', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '留空時使用 Amego 公開測試帳號（統編 12345678 / App Key sHeq7t8G1wiQvhAuIM27）。自家測試環境可在此覆蓋。', 'mo-ectools' ),
				'id'    => 'mo_amego_invoice_sandbox_section',
			],
			[
				'title' => __( '測試統編', 'mo-ectools' ),
				'id'    => 'mo_amego_invoice_sandbox_invoice_id',
				'type'  => 'text',
			],
			[
				'title' => __( '測試 App Key', 'mo-ectools' ),
				'id'    => 'mo_amego_invoice_sandbox_app_key',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_amego_invoice_sandbox_section',
			],

			[
				'title' => __( '正式環境憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '從 invoice.amego.tw 後台取得貴公司統編對應的 App Key。', 'mo-ectools' ),
				'id'    => 'mo_amego_invoice_prod_section',
			],
			[
				'title' => __( '正式統編', 'mo-ectools' ),
				'id'    => 'mo_amego_invoice_invoice_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 App Key', 'mo-ectools' ),
				'id'    => 'mo_amego_invoice_app_key',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_amego_invoice_prod_section',
			],
		];
	}
}
