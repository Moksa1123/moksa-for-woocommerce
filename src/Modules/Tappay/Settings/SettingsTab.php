<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Tappay\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '商家憑證', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '向 TapPay 申請取得 App ID / App Key / Partner Key / Merchant ID。請至 TapPay 後台把本站網址加入 App Key 的 allowed origin。', 'mo-ectools' ),
				'id'    => 'moksafowo_tappay_section',
			],
			[
				'title'   => __( '啟用測試模式', 'mo-ectools' ),
				'id'      => 'moksafowo_tappay_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( '勾選後走 sandbox.tappaysdk.com 沙箱不會真扣款。沙箱欄位全部留空時自動使用 TapPay 官方公開測試帳號（可用測試卡 4242 4242 4242 4242 / CVC 123 驗證）。上線前請取消勾選並填正式憑證。', 'mo-ectools' ),
			],
			[
				'title'    => __( '測試 App ID', 'mo-ectools' ),
				'id'       => 'moksafowo_tappay_sandbox_app_id',
				'type'     => 'text',
				'desc_tip' => __( '留空 = 用 TapPay 公開沙箱 App ID（11327）。', 'mo-ectools' ),
			],
			[
				'title'    => __( '測試 App Key', 'mo-ectools' ),
				'id'       => 'moksafowo_tappay_sandbox_app_key',
				'type'     => 'text',
				'desc_tip' => __( '前端 SDK key（app_ 開頭）。留空 = 用公開沙箱 App Key。', 'mo-ectools' ),
			],
			[
				'title'    => __( '測試 Partner Key', 'mo-ectools' ),
				'id'       => 'moksafowo_tappay_sandbox_partner_key',
				'type'     => 'text',
				'desc_tip' => __( 'Server 端 x-api-key（partner_ 開頭，機密）。留空 = 用公開沙箱 Partner Key。', 'mo-ectools' ),
			],
			[
				'title'    => __( '測試 Merchant ID', 'mo-ectools' ),
				'id'       => 'moksafowo_tappay_sandbox_merchant_id',
				'type'     => 'text',
				'desc_tip' => __( '留空 = 用 TapPay 沙箱共用 GlobalTesting_CTBC。', 'mo-ectools' ),
			],
			[
				'title'    => __( '測試 Webhook 簽章密鑰', 'mo-ectools' ),
				'id'       => 'moksafowo_tappay_sandbox_notify_secret',
				'type'     => 'text',
				'desc_tip' => __( 'TapPay 後台設定的 notify secret（驗 x-tappay-signature 用）。留空 = 用 Partner Key。', 'mo-ectools' ),
			],
			[
				'title' => __( '正式 App ID', 'mo-ectools' ),
				'id'    => 'moksafowo_tappay_app_id',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 App Key', 'mo-ectools' ),
				'id'    => 'moksafowo_tappay_app_key',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 Partner Key', 'mo-ectools' ),
				'id'    => 'moksafowo_tappay_partner_key',
				'type'  => 'text',
			],
			[
				'title' => __( '正式 Merchant ID', 'mo-ectools' ),
				'id'    => 'moksafowo_tappay_merchant_id',
				'type'  => 'text',
			],
			[
				'title'    => __( '正式 Webhook 簽章密鑰', 'mo-ectools' ),
				'id'       => 'moksafowo_tappay_notify_secret',
				'type'     => 'text',
				'desc_tip' => __( 'TapPay 後台設定的 notify secret。留空 = 用 Partner Key。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_tappay_section',
			],

			[
				'title' => __( '付款設定', 'mo-ectools' ),
				'type'  => 'title',
				'id'    => 'moksafowo_tappay_misc_section',
			],
			[
				'title'   => __( '啟用 3D 驗證（3DS）', 'mo-ectools' ),
				'id'      => 'moksafowo_tappay_3ds_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( '建議開啟（責任轉移、降低盜刷爭議）。開啟後付款會跳轉至發卡行 3D 驗證頁，完成後自動導回。', 'mo-ectools' ),
			],
			[
				'title'   => __( 'Debug 日誌', 'mo-ectools' ),
				'id'      => 'moksafowo_tappay_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '排查訂單異常時開啟。位置：WooCommerce → 狀態 → 日誌（來源 tappay-payment）。Partner Key / prime / 卡號等敏感欄位一律遮蔽。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_tappay_misc_section',
			],
		];
	}
}
