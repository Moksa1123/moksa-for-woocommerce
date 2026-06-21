<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\AiAssistant;

use MoksaWeb\Mowc\Modules\OrderLookup\BatchUpdateOrderStatus;
use MoksaWeb\Mowc\Modules\OrderLookup\ChannelOps;
use MoksaWeb\Mowc\Modules\OrderLookup\DonationOrgOps;
use MoksaWeb\Mowc\Modules\OrderLookup\InvoiceChannelOps;
use MoksaWeb\Mowc\Modules\OrderLookup\InvoiceOps;
use MoksaWeb\Mowc\Modules\OrderLookup\PaymentMethodOps;
use MoksaWeb\Mowc\Modules\OrderLookup\PrintShippingLabel;
use MoksaWeb\Mowc\Modules\OrderLookup\ResendPaymentEmail;
use MoksaWeb\Mowc\Modules\OrderLookup\ShipmentOps;
use MoksaWeb\Mowc\Modules\OrderLookup\ShippingZoneOps;
use MoksaWeb\Mowc\Modules\OrderLookup\UpdateOrderStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Moksa AI 共用設定 —— ability 白名單 + 系統提示 + 破壞性動作處理表。浮動窗與 REST 共用。
 */
final class Config {

	const CAP  = 'edit_shop_orders';
	const NAME = 'Moksa AI';

	/**
	 * 暴露給 AI 當工具的 ability 白名單(含破壞性的;破壞性會被 Agent 攔下走確認關卡)。
	 *
	 * @return string[]
	 */
	public static function abilities(): array {
		$abilities = array(
			'mo-ectools/find-order-by-number',
			'mo-ectools/get-order-details',
			'mo-ectools/query-orders',
			'mo-ectools/update-order-status',
			'mo-ectools/batch-update-order-status',
			'mo-ectools/issue-invoice',
			'mo-ectools/void-invoice',
			'mo-ectools/print-shipping-label',
			'mo-ectools/create-shipment',
			'mo-ectools/add-order-note',
			'mo-ectools/list-channels',
			'mo-ectools/toggle-channel',
			'mo-ectools/add-donation-org',
			'mo-ectools/issue-allowance',
			'mo-ectools/list-orders',
			'mo-ectools/list-payment-methods',
			'mo-ectools/toggle-payment-method',
			'mo-ectools/list-invoice-channels',
			'mo-ectools/toggle-invoice-channel',
			'mo-ectools/resend-payment-email',
			'mo-ectools/get-tracking-link',
			'mo-ectools/get-payment-status',
			'mo-ectools/get-plugin-settings',
			'mo-ectools/list-shipping-zones',
			'mo-ectools/toggle-shipping-method',
			'mo-ectools/batch-create-shipment',
			'mo-ectools/find-customer-orders',
			'mo-ectools/sales-summary',
		);
		return (array) apply_filters( 'moksafowo_ai_assistant_abilities', $abilities );
	}

	/**
	 * 破壞性動作處理表:ability 名稱 => [ prepare 驗證描述, apply 真正執行 ]。
	 * AI 呼叫這些 ability 時會被攔下,改走「人工確認」關卡。
	 *
	 * @return array<string, array{prepare:callable, apply:callable}>
	 */
	public static function destructive_handlers(): array {
		$handlers = array(
			'mo-ectools/update-order-status'       => array(
				'prepare' => array( UpdateOrderStatus::class, 'prepare' ),
				'apply'   => array( UpdateOrderStatus::class, 'apply' ),
			),
			'mo-ectools/batch-update-order-status' => array(
				'prepare' => array( BatchUpdateOrderStatus::class, 'prepare' ),
				'apply'   => array( BatchUpdateOrderStatus::class, 'apply' ),
			),
			'mo-ectools/issue-invoice'             => array(
				'prepare' => array( InvoiceOps::class, 'issue_prepare' ),
				'apply'   => array( InvoiceOps::class, 'issue_apply' ),
			),
			'mo-ectools/void-invoice'              => array(
				'prepare' => array( InvoiceOps::class, 'void_prepare' ),
				'apply'   => array( InvoiceOps::class, 'void_apply' ),
			),
			'mo-ectools/print-shipping-label'      => array(
				'prepare' => array( PrintShippingLabel::class, 'prepare' ),
				'apply'   => array( PrintShippingLabel::class, 'apply' ),
			),
			'mo-ectools/create-shipment'           => array(
				'prepare' => array( ShipmentOps::class, 'prepare' ),
				'apply'   => array( ShipmentOps::class, 'apply' ),
			),
			'mo-ectools/batch-create-shipment'     => array(
				'prepare' => array( ShipmentOps::class, 'batch_prepare' ),
				'apply'   => array( ShipmentOps::class, 'batch_apply' ),
			),
			'mo-ectools/toggle-channel'            => array(
				'prepare' => array( ChannelOps::class, 'toggle_prepare' ),
				'apply'   => array( ChannelOps::class, 'toggle_apply' ),
			),
			'mo-ectools/add-donation-org'          => array(
				'prepare' => array( DonationOrgOps::class, 'prepare' ),
				'apply'   => array( DonationOrgOps::class, 'apply' ),
			),
			'mo-ectools/issue-allowance'           => array(
				'prepare' => array( InvoiceOps::class, 'allowance_prepare' ),
				'apply'   => array( InvoiceOps::class, 'allowance_apply' ),
			),
			'mo-ectools/toggle-payment-method'     => array(
				'prepare' => array( PaymentMethodOps::class, 'toggle_prepare' ),
				'apply'   => array( PaymentMethodOps::class, 'toggle_apply' ),
			),
			'mo-ectools/toggle-invoice-channel'    => array(
				'prepare' => array( InvoiceChannelOps::class, 'toggle_prepare' ),
				'apply'   => array( InvoiceChannelOps::class, 'toggle_apply' ),
			),
			'mo-ectools/resend-payment-email'      => array(
				'prepare' => array( ResendPaymentEmail::class, 'prepare' ),
				'apply'   => array( ResendPaymentEmail::class, 'apply' ),
			),
			'mo-ectools/toggle-shipping-method'    => array(
				'prepare' => array( ShippingZoneOps::class, 'toggle_prepare' ),
				'apply'   => array( ShippingZoneOps::class, 'toggle_apply' ),
			),
		);
		return (array) apply_filters( 'moksafowo_ai_destructive_handlers', $handlers );
	}

	public static function destructive_abilities(): array {
		return array_keys( self::destructive_handlers() );
	}

	public static function system_instruction(): string {
		return __( '你是台灣電商商家的 WooCommerce 後台助手 Moksa AI。你有這些工具:(1) 依訂單編號、電子發票號碼、物流單號或金流交易序號查單筆訂單 —— 使用者直接給一組數字(如「訂單2855」或「2855 狀態?」)時,就把它當訂單編號用這個工具查,只需要狀態或找到是哪一筆時用它;(2) 查單筆訂單的完整明細(品項、金額、付款 / 配送方式、取貨門市、發票號碼與是否已開立、物流單號、金流序號)—— 被問到「發票開了嗎 / 物流單號 / 取哪間門市 / 買了什麼」就用這個;(3) 查訂單數量與各狀態筆數分布,或列出某狀態的訂單(例:待出貨 = processing、待付款 = pending、已完成 = completed);(4) 更改訂單狀態 —— 單筆用 update-order-status、一次多筆用 batch-update-order-status;(5) 開立電子發票 issue-invoice;(6) 作廢電子發票 void-invoice(需作廢原因);(7) 列印物流單 print-shipping-label(單筆或多筆,訂單需已建立託運單);(8) 建立託運單 create-shipment(向物流商建單取號,建單後才能列印)。第 4-8 項都會先提出、由系統要求使用者按「確認執行」後才動作,你不必自己再追問確認。(9) 新增訂單備註 add-order-note(低風險,直接執行);(10) 列出金流/物流/發票管道與啟用狀態 list-channels(唯讀);(11) 啟用/停用管道 toggle-channel(channel 可直接給管道名稱如「速買配物流」,不必先查清單;破壞性走確認);(12) 新增發票捐贈單位 add-donation-org(名稱+愛心碼;破壞性走確認);(13) 開立發票折讓單 issue-allowance(需金額;破壞性走確認);(14) 進階訂單列表 list-orders(依狀態/日期區間/金流篩選;唯讀報表);(15) 列出某金流的個別付款方式 list-payment-methods(唯讀);(16) 啟用/停用金流的個別付款方式 toggle-payment-method(如綠界的信用卡一次付清、Apple Pay;methods 直接給名稱;破壞性走確認);(17) 列出發票開立方式 list-invoice-channels(唯讀);(18) 啟用/停用發票開立方式 toggle-invoice-channel(會員載具/手機條碼/自然人憑證/紙本/捐贈/統編;破壞性走確認);(19) 重寄付款資訊信 resend-payment-email(ATM/超商繳費資訊給顧客;破壞性走確認);(20) 取貨態追蹤連結 get-tracking-link(唯讀);(21) 查付款狀態 get-payment-status(付款方式/是否已付/交易序號/卡末四/ATM/超商;藍新即時 B02;唯讀);(22) 彙整外掛設定 get-plugin-settings(哪些管道開著/測試正式/發票開立時機/查號欄位/AI 開關,不含憑證;唯讀);(23) 列出運送區域與方式 list-shipping-zones(唯讀);(24) 啟用/停用運送方式 toggle-shipping-method(WC 運送區域內的個別物流方式如「速買配黑貓常溫」;method 直接給名稱;破壞性走確認);(25) 批次建立託運單 batch-create-shipment(一次多筆建單取號;破壞性走確認);(26) 查顧客訂單 find-customer-orders(email/電話/姓名;唯讀);(27) 營收訂單統計 sales-summary(某期間營收/訂單數/狀態分布/客單價,不給日期=本月;唯讀)。第 11-13、16、18-19、24-25 項都先提出、確認後才動作。回答前務必先用工具查真實資料,再用繁體中文簡短清楚回答;只回報真正符合查詢的訂單,不要附帶不相關的訂單。查不到就明說,不要編造。若被問到目前工具做不到的操作(如批次列印物流單、大量改狀態),不要只說「無法」,要說明那可在 WooCommerce「訂單」列表勾選多筆後用上方的「批次操作」完成,我目前只能協助查詢與單筆狀態變更。若使用者一次問多件事,先用對應工具把所有需要的資料查齊,再把各項答案合併成一段繁體中文文字一次回覆;每次都務必以文字作結,不要只停在工具呼叫而沒給出文字答覆。', 'mo-ectools' );
	}
}
