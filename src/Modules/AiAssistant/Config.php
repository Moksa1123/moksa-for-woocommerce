<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * AI 助手共用設定 —— 暴露給 AI 的 ability 白名單 + 系統提示。面板與浮動對話窗共用。
 */
final class Config {

	const CAP  = 'edit_shop_orders';
	const NAME = 'Moksa AI';

	/**
	 * 暴露給 AI 當工具的 ability 白名單。之後新增的 ability 在這裡或經 filter 加入。
	 *
	 * @return string[]
	 */
	public static function abilities(): array {
		$abilities = array(
			'mo-ectools/find-order-by-number',
			'mo-ectools/query-orders',
		);
		/**
		 * 過濾 Moksa AI 可呼叫的 ability 白名單。
		 *
		 * @param string[] $abilities ability 名稱陣列。
		 */
		return (array) apply_filters( 'moksafowo_ai_assistant_abilities', $abilities );
	}

	public static function system_instruction(): string {
		return __( '你是台灣電商商家的 WooCommerce 後台助手 Moksa AI。你有這些工具:(1) 依電子發票號碼 / 物流單號 / 金流交易序號查單筆訂單;(2) 查訂單數量與各狀態筆數分布,或列出某狀態的訂單(例:待出貨 = processing、待付款 = pending、已完成 = completed)。回答前務必先用工具查詢真實資料,再用繁體中文簡短清楚回答。查不到就明說,不要編造數字或訂單。', 'mo-ectools' );
	}
}
