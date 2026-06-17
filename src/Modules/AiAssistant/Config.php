<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * AI 助手共用設定 —— 暴露給 AI 的 ability 白名單 + 系統提示。面板與浮動對話窗共用。
 */
final class Config {

	const CAP = 'edit_shop_orders';

	/**
	 * 暴露給 AI 當工具的 ability 白名單。目前只有查號;之後新增的 ability 在這裡或經 filter 加入。
	 *
	 * @return string[]
	 */
	public static function abilities(): array {
		$abilities = array( 'mo-ectools/find-order-by-number' );
		/**
		 * 過濾 AI 助手可呼叫的 ability 白名單。
		 *
		 * @param string[] $abilities ability 名稱陣列。
		 */
		return (array) apply_filters( 'moksafowo_ai_assistant_abilities', $abilities );
	}

	public static function system_instruction(): string {
		return __( '你是台灣電商商家的 WooCommerce 後台助手。商家詢問訂單相關問題時,務必使用提供的工具(可用電子發票號碼 / 物流單號 / 金流交易序號查訂單)查詢,再根據工具結果用繁體中文簡短回答,列出訂單編號、買家、狀態、金額。查不到就明說查不到,不要編造。', 'mo-ectools' );
	}
}
