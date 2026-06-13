<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayInvoice\Settings;

use MoksaWeb\Mowc\Modules\EcpayInvoice\Api\Helper;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '商家憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '從綠界發票後台「廠商專區 → 系統識別資料」複製過來。發票帳號跟金流 / 物流是<strong>獨立申請</strong>。', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_invoice_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_invoice_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( '上線前用，勾選後，所有發票走測試環境不會真開立。上線後請取消勾選。', 'mo-ectools' ),
			],
			[
				'title'   => __( '測試 MerchantID', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_invoice_sandbox_merchant_id',
				'type'    => 'text',
				'default' => Helper::SANDBOX_MERCHANT_ID,
			],
			[
				'title'   => __( '測試 HashKey', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_invoice_sandbox_hash_key',
				'type'    => 'text',
				'default' => Helper::SANDBOX_HASH_KEY,
			],
			[
				'title'   => __( '測試 HashIV', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_invoice_sandbox_hash_iv',
				'type'    => 'text',
				'default' => Helper::SANDBOX_HASH_IV,
			],
			[
				'title' => __( '正式 MerchantID', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_invoice_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashKey', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_invoice_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashIV', 'mo-ectools' ),
				'id'    => 'moksafowo_ecpay_invoice_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_invoice_section',
			],

			[
				'title' => __( '開立規則', 'mo-ectools' ),
				'type'  => 'title',
				'id'    => 'moksafowo_ecpay_invoice_behavior_section',
			],
			[
				'title'             => __( '發票編號前綴', 'mo-ectools' ),
				'id'                => 'moksafowo_ecpay_invoice_prefix',
				'type'              => 'text',
				'default'           => '',
				'desc_tip'          => __( '最多 5 字元（限英數字）。同一個綠界發票帳號跨多店時，用前綴避免發票號撞號。', 'mo-ectools' ),
				'custom_attributes' => [ 'pattern' => '[a-zA-Z0-9]{0,5}', 'maxlength' => 5 ],
			],
			[
				'title'   => __( '何時開立', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_invoice_issue_when',
				'type'    => 'select',
				'options' => [
					'paid'      => __( '付款完成立即開立（建議）', 'mo-ectools' ),
					'completed' => __( '訂單完成（出貨後）才開立', 'mo-ectools' ),
					'manual'    => __( '只手動開立（後台按鈕觸發）', 'mo-ectools' ),
				],
				'default' => 'paid',
			],
			[
				'title'             => __( '延後開立天數', 'mo-ectools' ),
				'id'                => 'moksafowo_ecpay_invoice_delay_days',
				'type'              => 'number',
				'default'           => 0,
				'desc_tip'          => __( '達到「何時開立」條件後再延幾天才實際開立。預設 0 = 立即。常見：訂單完成後 N 天才開（避免取消退款）。', 'mo-ectools' ),
				'custom_attributes' => [ 'min' => 0, 'max' => 30, 'step' => 1 ],
			],
			[
				'title'   => __( '訂單退款 / 取消時自動作廢發票', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_invoice_auto_cancel',
				'type'    => 'select',
				'default' => 'manual',
				'options' => [
					'manual'      => __( '手動 — 商家自己進訂單編輯頁按「作廢發票」', 'mo-ectools' ),
					'auto_cancel' => __( '自動 — 訂單變取消 / 退款 / 失敗時 2 分鐘後自動作廢', 'mo-ectools' ),
				],
				'desc'    => __( '預設「手動」最保守。改成「自動」後，訂單變取消 / 退款 / 失敗時會在 2 分鐘後自動觸發作廢（buffer 期間切回正常狀態可救回）。已開立的發票會打綠界 Invalid API；還在排程未開的會直接取消排程。避免商家手動忘記作廢造成稅務問題。', 'mo-ectools' ),
			],
			[
				'title'   => __( '載具 / 愛心碼真驗', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_invoice_carrier_api_check',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( '結帳時呼叫綠界 API 真的查財政部資料庫，確認顧客輸入的手機條碼 / 愛心碼存在。預設開啟避免顧客輸入合法格式但偽造的載具導致發票開立失敗。財政部 API 維護中時自動跳過放行。', 'mo-ectools' ),
			],
			[
				'title'   => __( '結帳允許捐贈', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_invoice_allow_donate',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( '提供「捐贈發票」選項（消費者輸入 3-7 碼捐贈碼）。', 'mo-ectools' ),
			],
			[
				'title'       => __( '捐贈單位', 'mo-ectools' ),
				'id'          => 'moksafowo_ecpay_invoice_donate_orgs',
				'type'        => 'textarea',
				'default'     => '',
				'css'         => 'min-height:90px;width:100%;',
				'placeholder' => '伊甸社會福利基金會|25885',
				'desc_tip'    => __( '輸入捐贈機構（每行一筆），格式為：社福團體名稱|愛心碼（例：伊甸社會福利基金會|25885）。填了之後顧客結帳與後台手動開立改用下拉選單挑單位（看得到名稱）；留空則由顧客自行輸入愛心碼。', 'mo-ectools' ),
			],
			[
				'title'   => __( '結帳允許統編（公司用三聯式）', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_invoice_allow_b2b',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( '提供統一編號欄位，公司行號可開三聯式發票。', 'mo-ectools' ),
			],
			[
				'title'         => __( '開放的個人載具', 'mo-ectools' ),
				'desc'          => __( '會員載具（雲端，免輸入）', 'mo-ectools' ),
				'id'            => 'moksafowo_ecpay_invoice_channel_member',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			],
			[
				'desc'          => __( '手機條碼', 'mo-ectools' ),
				'id'            => 'moksafowo_ecpay_invoice_channel_mobile',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '自然人憑證', 'mo-ectools' ),
				'id'            => 'moksafowo_ecpay_invoice_channel_cert',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( '紙本', 'mo-ectools' ),
				'id'            => 'moksafowo_ecpay_invoice_channel_paper',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'end',
				'desc_tip'      => __( '勾選要在結帳頁與後台手動開立提供的個人載具。至少留一項；全不勾會自動保留紙本。', 'mo-ectools' ),
			],
			[
				'title'   => __( '個人預設載具', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_invoice_default_carrier',
				'type'    => 'select',
				'options' => [
					'member' => __( '會員載具（雲端，免輸入）', 'mo-ectools' ),
					'mobile' => __( '手機條碼（消費者輸入 8 碼）', 'mo-ectools' ),
					'cert'   => __( '自然人憑證（消費者輸入 16 碼）', 'mo-ectools' ),
					'paper'  => __( '紙本（不發載具）', 'mo-ectools' ),
				],
				'default' => 'member',
				'desc_tip' => __( '結帳頁與後台手動開立的預設選取載具（須是上方有開放的）。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_invoice_behavior_section',
			],

			[
				'title' => __( '其他', 'mo-ectools' ),
				'type'  => 'title',
				'id'    => 'moksafowo_ecpay_invoice_debug_section',
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'moksafowo_ecpay_invoice_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查發票異常時開啟。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_invoice_debug_section',
			],
		];
	}
}
