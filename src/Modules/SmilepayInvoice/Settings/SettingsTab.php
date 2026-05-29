<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayInvoice\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '基本設定', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( 'SmilePay 電子發票串接。商家須自行至 https://www.smilepay.net 申請會員、開立商店、取得 Grvc + Verify_key 雙密鑰。', 'mo-ectools' ),
				'id'    => 'mo_smilepay_invoice_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'mo_smilepay_invoice_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後走 ssl.smse.com.tw/api_test 測試環境。預設關閉（SmilePay 沒公開測試帳號，須向業務窗口申請測試憑證）— 開發階段才開啟。', 'mo-ectools' ),
			],
			[
				'title'   => __( '訂單編號前綴', 'mo-ectools' ),
				'id'      => 'mo_smilepay_invoice_order_prefix',
				'type'    => 'text',
				'default' => '',
				'desc'    => __( '商家自定的訂單前綴（限英數，最多 5 字元）。送進 SmilePay 的單號會以此開頭。留空即無前綴。', 'mo-ectools' ),
				'desc_tip' => true,
				'custom_attributes' => [ 'pattern' => '[A-Za-z0-9]{0,5}', 'maxlength' => 5 ],
			],
			[
				'title' => __( '字軌 TrackSystemID', 'mo-ectools' ),
				'id'    => 'mo_smilepay_invoice_track_system_id',
				'type'  => 'text',
				'desc'  => __( 'SmilePay 後台 → 字軌管理頁取得的字軌系統碼。多本字軌時要指定用哪一本。', 'mo-ectools' ),
				'desc_tip' => true,
			],
			[
				'title'   => __( '開立時機', 'mo-ectools' ),
				'id'      => 'mo_smilepay_invoice_issue_when',
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
				'id'      => 'mo_smilepay_invoice_auto_cancel',
				'type'    => 'select',
				'default' => 'manual',
				'options' => [
					'manual'      => __( '手動 — 商家自己進訂單編輯頁按「作廢發票」', 'mo-ectools' ),
					'auto_cancel' => __( '自動 — 訂單變取消 / 退款 / 失敗時 2 分鐘後自動作廢', 'mo-ectools' ),
				],
			],
			[
				'title'   => __( '結帳允許捐贈', 'mo-ectools' ),
				'id'      => 'mo_smilepay_invoice_allow_donate',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( '結帳允許統編（公司用三聯式）', 'mo-ectools' ),
				'id'      => 'mo_smilepay_invoice_allow_b2b',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'mo_smilepay_invoice_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_smilepay_invoice_section',
			],

			[
				'title' => __( '測試環境憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( 'SmilePay 沒有公開測試帳號，需自行向業務窗口申請測試串接。', 'mo-ectools' ),
				'id'    => 'mo_smilepay_invoice_sandbox_section',
			],
			[
				'title' => __( '測試 Grvc', 'mo-ectools' ),
				'id'    => 'mo_smilepay_invoice_sandbox_grvc',
				'type'  => 'text',
				'desc'  => __( '商家串接代號。', 'mo-ectools' ),
			],
			[
				'title' => __( '測試 Verify_key', 'mo-ectools' ),
				'id'    => 'mo_smilepay_invoice_sandbox_verify_key',
				'type'  => 'text',
				'desc'  => __( '驗證碼。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_smilepay_invoice_sandbox_section',
			],

			[
				'title' => __( '正式環境憑證', 'mo-ectools' ),
				'type'  => 'title',
				'id'    => 'mo_smilepay_invoice_prod_section',
			],
			[
				'title' => __( '正式 Grvc', 'mo-ectools' ),
				'id'    => 'mo_smilepay_invoice_grvc',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 Verify_key', 'mo-ectools' ),
				'id'    => 'mo_smilepay_invoice_verify_key',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'mo_smilepay_invoice_prod_section',
			],
		];
	}
}
