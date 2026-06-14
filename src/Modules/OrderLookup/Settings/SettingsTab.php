<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( '訂單查號搜尋', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '讓 WooCommerce 訂單搜尋框（及 Ctrl+K 命令面板）認得台灣特有的號碼。Tier 1（發票號 / 物流單號 / 金流交易序號）預設開啟，只搜尋已啟用模組的號碼。', 'mo-ectools' ),
				'id'    => 'moksafowo_order_lookup_section',
			],
			[
				'title'   => __( '進階查號欄位（Tier 2）', 'mo-ectools' ),
				'id'      => 'moksafowo_order_lookup_tier2',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '額外納入：統一編號、ATM 虛擬帳號、超商繳費代碼、卡末四碼、黑貓追蹤號。欄位較多、搜尋稍慢，需要才開。', 'mo-ectools' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_order_lookup_section',
			],
		];
	}
}
