<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\AmegoInvoice\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '基本設定', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'AMEGO 光貿電子發票串接。已內建公開測試帳號，勾選測試模式即可直接試開立；上線請填入下方正式環境憑證。', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_section',
			],
			[
				'title'   => __( '啟用測試模式', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_amego_invoice_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後使用內建公開測試憑證（若下方有自填測試憑證則以自填為準）。', 'moksa-for-woocommerce' ),
			],
			[
				'title'             => __( '訂單編號前綴', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_amego_invoice_order_prefix',
				'type'              => 'text',
				'default'           => '',
				'desc'              => __( '送至 AMEGO 的訂單編號前綴（限英數，最多 5 字元）。留空即無前綴。', 'moksa-for-woocommerce' ),
				'desc_tip'          => true,
				'custom_attributes' => [
					'pattern'   => '[A-Za-z0-9]{0,5}',
					'maxlength' => 5,
				],
			],
			[
				'title'   => __( '開立時機', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_amego_invoice_issue_when',
				'type'    => 'select',
				'default' => 'paid',
				'options' => [
					'paid'      => __( '付款完成立即開立', 'moksa-for-woocommerce' ),
					'completed' => __( '訂單完成後才開立', 'moksa-for-woocommerce' ),
					'manual'    => __( '手動 — 商家在訂單頁按按鈕開立', 'moksa-for-woocommerce' ),
				],
			],
			[
				'title'   => __( '訂單退款 / 取消時自動作廢發票', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_amego_invoice_auto_cancel',
				'type'    => 'select',
				'default' => 'manual',
				'options' => [
					'manual'      => __( '手動', 'moksa-for-woocommerce' ),
					'auto_cancel' => __( '自動 — 訂單變取消 / 退款 / 失敗時 2 分鐘後自動作廢', 'moksa-for-woocommerce' ),
				],
			],
			[
				'title'   => __( '結帳允許捐贈', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_amego_invoice_allow_donate',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( '結帳允許統編（公司用三聯式）', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_amego_invoice_allow_b2b',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'         => __( '開放的個人載具', 'moksa-for-woocommerce' ),
				'desc'          => __( 'AMEGO 會員載具', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_amego_invoice_channel_member',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			],
			[
				'desc'          => __( '手機條碼', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_amego_invoice_channel_mobile',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '自然人憑證', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_amego_invoice_channel_cert',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '紙本發票', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_amego_invoice_channel_paper',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'end',
				'desc_tip'      => __( '勾選要在結帳頁與後台手動開立提供的個人載具。至少留一項；全不勾會自動保留紙本。', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( '個人預設載具', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_amego_invoice_default_carrier',
				'type'     => 'select',
				'options'  => [
					'member' => __( 'AMEGO 會員載具', 'moksa-for-woocommerce' ),
					'mobile' => __( '手機條碼', 'moksa-for-woocommerce' ),
					'cert'   => __( '自然人憑證', 'moksa-for-woocommerce' ),
					'paper'  => __( '紙本發票', 'moksa-for-woocommerce' ),
				],
				'default'  => 'member',
				'desc_tip' => __( '結帳頁與後台手動開立的預設選取載具（須是上方有開放的）。', 'moksa-for-woocommerce' ),
			],
			[
				'title'       => __( '捐贈單位', 'moksa-for-woocommerce' ),
				'id'          => 'moksafowo_amego_invoice_donate_orgs',
				'type'        => 'textarea',
				'default'     => '',
				'css'         => 'min-height:90px;width:100%;',
				'placeholder' => '伊甸社會福利基金會|25885',
				'desc_tip'    => __( '輸入捐贈機構（每行一筆），格式為：社福團體名稱|愛心碼（例：伊甸社會福利基金會|25885）。填了之後顧客結帳與後台手動開立改用下拉選單挑單位（看得到名稱）；留空則由顧客自行輸入愛心碼。', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( 'Debug 日誌', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_amego_invoice_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_amego_invoice_section',
			],

			[
				'title' => __( '測試環境憑證（選填）', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( '留空時使用內建公開測試帳號。要用自家測試環境可在此覆蓋。', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_sandbox_section',
			],
			[
				'title' => __( '測試統編', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_sandbox_invoice_id',
				'type'  => 'text',
			],
			[
				'title' => __( '測試 App Key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_sandbox_app_key',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_amego_invoice_sandbox_section',
			],

			[
				'title' => __( '正式環境憑證', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( '從 invoice.amego.tw 後台取得貴公司統編對應的 App Key。', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_prod_section',
			],
			[
				'title' => __( '正式統編', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_invoice_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 App Key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_app_key',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_amego_invoice_prod_section',
			],
		];
	}
}
