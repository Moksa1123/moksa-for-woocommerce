<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayInvoice\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '基本設定', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( 'SmilePay 電子發票串接。請先至 SmilePay 申請會員、開立商店，取得商店憑證後填入下方。', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_invoice_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'moksafowo_smilepay_invoice_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後走 SmilePay 測試環境，不會真正開立。SmilePay 沒有公開測試帳號，須向業務窗口申請測試憑證後才能使用。', 'mo-ectools' ),
			],
			[
				'title'             => __( '訂單編號前綴', 'mo-ectools' ),
				'id'                => 'moksafowo_smilepay_invoice_order_prefix',
				'type'              => 'text',
				'default'           => '',
				'desc'              => __( '送至 SmilePay 的訂單編號前綴（限英數，最多 5 字元）。留空即無前綴。', 'mo-ectools' ),
				'desc_tip'          => true,
				'custom_attributes' => [
					'pattern'   => '[A-Za-z0-9]{0,5}',
					'maxlength' => 5,
				],
			],
			[
				'title'    => __( '字軌系統碼', 'mo-ectools' ),
				'id'       => 'moksafowo_smilepay_invoice_track_system_id',
				'type'     => 'text',
				'desc'     => __( '從 SmilePay 後台字軌管理頁取得。有多本字軌時用來指定使用哪一本。', 'mo-ectools' ),
				'desc_tip' => true,
			],
			[
				'title'   => __( '開立時機', 'mo-ectools' ),
				'id'      => 'moksafowo_smilepay_invoice_issue_when',
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
				'id'      => 'moksafowo_smilepay_invoice_auto_cancel',
				'type'    => 'select',
				'default' => 'manual',
				'options' => [
					'manual'      => __( '手動 — 商家自己進訂單編輯頁按「作廢發票」', 'mo-ectools' ),
					'auto_cancel' => __( '自動 — 訂單變取消 / 退款 / 失敗時 2 分鐘後自動作廢', 'mo-ectools' ),
				],
			],
			[
				'title'   => __( '結帳允許捐贈', 'mo-ectools' ),
				'id'      => 'moksafowo_smilepay_invoice_allow_donate',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( '結帳允許統編（公司用三聯式）', 'mo-ectools' ),
				'id'      => 'moksafowo_smilepay_invoice_allow_b2b',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'         => __( '開放的個人載具', 'mo-ectools' ),
				'desc'          => __( 'SmilePay 會員載具', 'mo-ectools' ),
				'id'            => 'moksafowo_smilepay_invoice_channel_member',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			],
			[
				'desc'          => __( '手機條碼', 'mo-ectools' ),
				'id'            => 'moksafowo_smilepay_invoice_channel_mobile',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '自然人憑證', 'mo-ectools' ),
				'id'            => 'moksafowo_smilepay_invoice_channel_cert',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '紙本發票', 'mo-ectools' ),
				'id'            => 'moksafowo_smilepay_invoice_channel_paper',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'end',
				'desc_tip'      => __( '勾選要在結帳頁與後台手動開立提供的個人載具。至少留一項；全不勾會自動保留紙本。', 'mo-ectools' ),
			],
			[
				'title'    => __( '個人預設載具', 'mo-ectools' ),
				'id'       => 'moksafowo_smilepay_invoice_default_carrier',
				'type'     => 'select',
				'options'  => [
					'member' => __( 'SmilePay 會員載具', 'mo-ectools' ),
					'mobile' => __( '手機條碼', 'mo-ectools' ),
					'cert'   => __( '自然人憑證', 'mo-ectools' ),
					'paper'  => __( '紙本發票', 'mo-ectools' ),
				],
				'default'  => 'member',
				'desc_tip' => __( '結帳頁與後台手動開立的預設選取載具（須是上方有開放的）。', 'mo-ectools' ),
			],
			[
				'title'       => __( '捐贈單位', 'mo-ectools' ),
				'id'          => 'moksafowo_smilepay_invoice_donate_orgs',
				'type'        => 'textarea',
				'default'     => '',
				'css'         => 'min-height:90px;width:100%;',
				'placeholder' => '伊甸社會福利基金會|25885',
				'desc_tip'    => __( '輸入捐贈機構（每行一筆），格式為：社福團體名稱|愛心碼（例：伊甸社會福利基金會|25885）。填了之後顧客結帳與後台手動開立改用下拉選單挑單位（看得到名稱）；留空則由顧客自行輸入愛心碼。', 'mo-ectools' ),
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'moksafowo_smilepay_invoice_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_invoice_section',
			],

			[
				'title' => __( '測試環境憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( 'SmilePay 沒有公開測試帳號，需自行向業務窗口申請測試串接。', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_invoice_sandbox_section',
			],
			[
				'title' => __( '測試 Grvc', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_invoice_sandbox_grvc',
				'type'  => 'text',
				'desc'  => __( '商家串接代號。', 'mo-ectools' ),
			],
			[
				'title' => __( '測試 Verify_key', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_invoice_sandbox_verify_key',
				'type'  => 'text',
				'desc'  => __( '驗證碼。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_invoice_sandbox_section',
			],

			[
				'title' => __( '正式環境憑證', 'mo-ectools' ),
				'type'  => 'title',
				'id'    => 'moksafowo_smilepay_invoice_prod_section',
			],
			[
				'title' => __( '正式 Grvc', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_invoice_grvc',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 Verify_key', 'mo-ectools' ),
				'id'    => 'moksafowo_smilepay_invoice_verify_key',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_invoice_prod_section',
			],
		];
	}
}
