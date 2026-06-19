<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\NewebpayShipping\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '基本設定', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '物流憑證設定完，到「WooCommerce → 設定 → 運送方式」啟用配送區域。', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'moksafowo_newebpay_shipping_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '勾選後走測試環境，不會真出貨。預設關閉；留空時沿用藍新金流的測試模式設定。', 'mo-ectools' ),
			],
			[
				'title'    => __( '物流類型', 'mo-ectools' ),
				'id'       => 'moksafowo_newebpay_shipping_lgs_type',
				'type'     => 'select',
				'default'  => 'B2C',
				'options'  => [
					'B2C' => __( '商家出貨（預設，需在藍新後台開通物流帳號）', 'mo-ectools' ),
					'C2C' => __( '個人寄件', 'mo-ectools' ),
				],
				'desc'     => __( '需依藍新後台開通的物流服務決定。', 'mo-ectools' ),
				'desc_tip' => true,
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_shipping_section',
			],

			[
				'title' => __( '測試環境憑證（可選）', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '留空 = 沿用藍新金流的測試憑證。若藍新物流帳號獨立才需在此填入。', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_sandbox_section',
			],
			[
				'title' => __( '測試 MerchantID', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_sandbox_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '測試 HashKey', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_sandbox_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( '測試 HashIV', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_sandbox_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_shipping_sandbox_section',
			],

			[
				'title' => __( '正式環境憑證（可選）', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '留空 = 沿用藍新金流的正式憑證。', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_prod_section',
			],
			[
				'title' => __( '正式 MerchantID', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashKey', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 HashIV', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_shipping_prod_section',
			],

			[
				'title' => __( '啟用的超商', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '結帳頁顯示哪幾家超商可供顧客選取。', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_carriers_section',
			],
			[
				'title'   => __( '允許的超商品牌', 'mo-ectools' ),
				'id'      => 'moksafowo_newebpay_shipping_enabled_carriers',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'1' => __( '7-11', 'mo-ectools' ),
					'2' => __( '全家', 'mo-ectools' ),
					'3' => __( '萊爾富', 'mo-ectools' ),
					'4' => __( 'OK', 'mo-ectools' ),
				],
				'default' => [ '1', '2', '3', '4' ],
				'desc'    => __( '空白 = 全開（7-11 / 全家 / 萊爾富 / OK 4 家）。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_shipping_carriers_section',
			],

			[
				'title' => __( '寄件人資料', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '超商取貨標籤上的寄件人資訊，宅配時供物流商聯絡。', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_sender_section',
			],
			[
				'title' => __( '寄件人姓名', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_sender_name',
				'type'  => 'text',
				'desc'  => __( '中文 1-10 字 / 英文 1-20 字。', 'mo-ectools' ),
			],
			[
				'title' => __( '寄件人電話', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_sender_phone',
				'type'  => 'text',
				'desc'  => __( '室內或行動電話。', 'mo-ectools' ),
			],
			[
				'title' => __( '寄件人手機', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_sender_cellphone',
				'type'  => 'text',
				'desc'  => __( '建議填，方便藍新或超商聯絡。', 'mo-ectools' ),
			],
			[
				'title' => __( '寄件人 Email', 'mo-ectools' ),
				'id'    => 'moksafowo_newebpay_shipping_sender_email',
				'type'  => 'email',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_shipping_sender_section',
			],

			[
				'title' => __( '其他', 'mo-ectools' ),
				'type'  => 'title',
				'id'    => 'moksafowo_newebpay_shipping_misc_section',
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'moksafowo_newebpay_shipping_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查物流單異常時開啟。位置：WooCommerce → 狀態 → 日誌。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_shipping_misc_section',
			],
		];
	}
}
