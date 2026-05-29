<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PaynowInvoice\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '基本設定', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( 'PayNow 立即富電子發票串接（PayNow_EInvoice v1.5）。商家須自行至 invoice.paynow.com.tw 申請會員、取得 mem_cid 與 mem_password。', 'mo-ectools' ),
				'id'    => 'mo_paynow_invoice_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'mo_paynow_invoice_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後走 testinvoice.paynow.com.tw 測試環境。', 'mo-ectools' ),
			],
			[
				'title'   => __( 'orderno 前綴', 'mo-ectools' ),
				'id'      => 'mo_paynow_invoice_orderno_prefix',
				'type'    => 'text',
				'default' => '',
				'desc'    => __( '送至 PayNow 的 orderno 前綴（限英數，最多 5 字元）。留空即無前綴。', 'mo-ectools' ),
				'desc_tip' => true,
				'custom_attributes' => [ 'pattern' => '[A-Za-z0-9]{0,5}', 'maxlength' => 5 ],
			],
			[
				'title'   => __( '開立時機', 'mo-ectools' ),
				'id'      => 'mo_paynow_invoice_issue_when',
				'type'    => 'select',
				'default' => 'paid',
				'options' => [
					'paid'      => __( '付款完成 — 訂單變 processing 就立即開立', 'mo-ectools' ),
					'completed' => __( '訂單完成 — 訂單變 completed 才開立', 'mo-ectools' ),
					'manual'    => __( '手動 — 商家在訂單頁按按鈕開立', 'mo-ectools' ),
				],
			],
			[
				'title'   => __( '訂單退款 / 取消時自動作廢發票', 'mo-ectools' ),
				'id'      => 'mo_paynow_invoice_auto_cancel',
				'type'    => 'select',
				'default' => 'manual',
				'options' => [
					'manual'      => __( '手動 — 商家自己進訂單編輯頁按「作廢發票」', 'mo-ectools' ),
					'auto_cancel' => __( '自動 — 訂單變取消 / 退款 / 失敗時 2 分鐘後自動作廢', 'mo-ectools' ),
				],
			],
			[
				'title'   => __( '結帳允許捐贈', 'mo-ectools' ),
				'id'      => 'mo_paynow_invoice_allow_donate',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( '結帳允許統編（公司用三聯式）', 'mo-ectools' ),
				'id'      => 'mo_paynow_invoice_allow_b2b',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'mo_paynow_invoice_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_paynow_invoice_section',
			],

			[
				'title' => __( '測試環境憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: PayNow invoice test URL */
					__( '從 %s 申請。PayNow 沒有公開共用測試帳號。', 'mo-ectools' ),
					'<a href="https://testinvoice.paynow.com.tw" target="_blank">testinvoice.paynow.com.tw</a>'
				),
				'id'    => 'mo_paynow_invoice_sandbox_section',
			],
			[
				'title' => __( '測試 mem_cid', 'mo-ectools' ),
				'id'    => 'mo_paynow_invoice_sandbox_mem_cid',
				'type'  => 'text',
			],
			[
				'title' => __( '測試 mem_password', 'mo-ectools' ),
				'id'    => 'mo_paynow_invoice_sandbox_mem_password',
				'type'  => 'text',
				'desc'  => __( '8 字元（用於 3DES key 推導，spec §2.2）。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_paynow_invoice_sandbox_section',
			],

			[
				'title' => __( '正式環境憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '從 invoice.paynow.com.tw 申請。需 PayNow 審核啟用。', 'mo-ectools' ),
				'id'    => 'mo_paynow_invoice_prod_section',
			],
			[
				'title' => __( '正式 mem_cid', 'mo-ectools' ),
				'id'    => 'mo_paynow_invoice_mem_cid',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 mem_password', 'mo-ectools' ),
				'id'    => 'mo_paynow_invoice_mem_password',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_paynow_invoice_prod_section',
			],
		];
	}
}
