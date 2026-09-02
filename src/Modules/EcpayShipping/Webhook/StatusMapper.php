<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Webhook;

use Moksafowo\Modules\Shipping\Webhook\AbstractStatusMapper;

defined( 'ABSPATH' ) || exit;

final class StatusMapper extends AbstractStatusMapper {

	// 碼義以綠界物流貨態表為準。C2C（交貨便）走 2xxx、B2C 店配走 3xxx、
	// 宅配 / 郵局走 3xxx 後段與 5xxx；同一句中文在兩個號段各有一組碼，不可混用。
	private const MAP = [
		// 出貨
		'2030' => 'moksa-shipped',      // 物流中心驗收成功（C2C）
		'2068' => 'moksa-shipped',      // 賣家已到門市寄件（C2C）
		'3001' => 'moksa-shipped',      // 轉運中（集貨）
		'3006' => 'moksa-shipped',      // 配送中
		'3024' => 'moksa-shipped',      // 物流中心驗收成功（B2C）
		'3032' => 'moksa-shipped',      // 賣家已到門市寄件（B2C）
		'3119' => 'moksa-shipped',      // 暫置營業所（假日）
		'3301' => 'moksa-shipped',      // 交寄郵件
		'3312' => 'moksa-shipped',      // 貨件投遞中

		// 到店待取
		'2063' => 'moksa-cvs-arrived',  // 包裹配達取件門市（C2C）
		'2073' => 'moksa-cvs-arrived',  // 包裹配達取件門市（C2C）
		'3018' => 'moksa-cvs-arrived',  // 包裹配達取件門市（B2C）
		'3029' => 'moksa-cvs-arrived',  // 包裹已配達指定取件門市
		'3314' => 'moksa-cvs-arrived',  // 到達 i 郵箱

		// 取貨 / 送達完成
		'2067' => 'completed',          // 買家已到店取貨（C2C）
		'3022' => 'completed',          // 買家已到店取貨（B2C）
		'3003' => 'completed',          // 配完（宅配）
		'3307' => 'completed',          // i 郵箱取件成功
		'3308' => 'completed',          // 投遞成功
		'3309' => 'completed',          // 投遞成功（收受人領取）

		// 門市關轉 — 需重選門市或改走退貨
		'2016' => 'moksa-store-closed', // 門市已關轉店，將進行退貨處理
		'2026' => 'moksa-store-closed', // 無此門市，將進行退貨處理
		'2037' => 'moksa-store-closed', // 取件門市關轉，請重選門市
		'2049' => 'moksa-store-closed', // 門市關店，將進行退貨處理
		'2050' => 'moksa-store-closed', // 門市轉店，將進行退貨處理
		'2101' => 'moksa-store-closed', // 門市關轉店
		'2104' => 'moksa-store-closed', // 門市關轉，請重選門市

		// 未取件 —— 買家沒取貨的完整貨態鏈，從「未取」一路到「賣家取回包裹」。
		// 全部停在未取件，不自動轉已退費：退不退款是商家決定，這裡只反映物流事實。
		'2065' => 'moksa-unclaimed', // 買家未取包裹，將退回物流中心
		'2074' => 'moksa-unclaimed', // 買家未取包裹，將退回物流中心
		'2076' => 'moksa-unclaimed', // 買家未取包裹，已退回物流中心
		'2078' => 'moksa-unclaimed', // 買家未取包裹，已退回物流中心
		'2079' => 'moksa-unclaimed', // 買家未取退回 — 商品瑕疵
		'2080' => 'moksa-unclaimed', // 買家未取退回 — 超材
		'2081' => 'moksa-unclaimed', // 買家未取退回 — 違禁品
		'2082' => 'moksa-unclaimed', // 買家未取退回 — 訂單資料重複上傳
		'2083' => 'moksa-unclaimed', // 買家未取退回 — 已過門市進貨日
		'2084' => 'moksa-unclaimed', // 買家未取退回 — 第一段標籤規格錯誤
		'2085' => 'moksa-unclaimed', // 買家未取退回 — 第一段標籤無法判讀
		'2086' => 'moksa-unclaimed', // 買家未取退回 — 第一段標籤資料錯誤
		'2087' => 'moksa-unclaimed', // 買家未取退回 — 物流中心理貨中
		'2088' => 'moksa-unclaimed', // 買家未取退回 — 商品遺失
		'2089' => 'moksa-unclaimed', // 買家未取退回 — 門市指定不配送
		'2092' => 'moksa-unclaimed', // 買家未取退回 — 門市關轉
		'2093' => 'moksa-unclaimed', // 買家未取退回 — 爆量
		'2072' => 'moksa-unclaimed', // 包裹已退至原寄件門市（C2C）
		'2044' => 'moksa-unclaimed', // 賣家已取退回包裹（C2C）
		'2070' => 'moksa-unclaimed', // 賣家已取退回包裹（C2C）
		'3020' => 'moksa-unclaimed', // 買家未取包裹，將退回物流中心（B2C）
		'3025' => 'moksa-unclaimed', // 買家未取包裹，已退回物流中心（B2C）
		'3019' => 'moksa-unclaimed', // 包裹已退至原寄件門市（B2C）
		'3031' => 'moksa-unclaimed', // 包裹已退至指定寄件門市
		'3023' => 'moksa-unclaimed', // 賣家已取退回包裹（B2C）
		'3310' => 'moksa-unclaimed', // 已退回寄件人（宅配 / 郵局）

		// 配送失敗 / 貨件異常
		'2033' => 'failed',             // 包裹超材，退回賣家
		'2034' => 'failed',             // 違禁品
		'2042' => 'failed',             // 包裹遺失，進入賠償程序
		'3303' => 'failed',             // 投遞不成功
		'3305' => 'failed',             // 拒收
		'3306' => 'failed',             // 退回投遞不成功
		'4004' => 'failed',             // 包裹遺失，進入賠償程序
		'5001' => 'failed',             // 損壞，站所將協助退貨
		'5002' => 'failed',             // 遺失
	];

	public static function init(): void {
		add_action( 'moksafowo_ecpay_shipping_status_received', [ __CLASS__, 'handle_legacy_action' ], 20, 3 );
	}

	public static function handle_legacy_action( \WC_Order $order, string $code, string $msg ): void {
		( new self() )->handle_status_received( $order, $code, $msg );
	}

	protected function provider_slug(): string {
		return 'ecpay';
	}

	protected function code_map(): array {
		return self::MAP;
	}
}
