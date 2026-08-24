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

		// 退貨完成（包裹已回到賣家手上或原寄件門市）
		'2044' => 'refunded',           // 賣家已取退回包裹（C2C）
		'2070' => 'refunded',           // 賣家已取退回包裹（C2C）
		'2072' => 'refunded',           // 包裹已退至原寄件門市（C2C）
		'2076' => 'refunded',           // 買家未取包裹，已退回物流中心
		'2078' => 'refunded',           // 買家未取包裹，已退回物流中心
		'3019' => 'refunded',           // 包裹已退至原寄件門市（B2C）
		'3023' => 'refunded',           // 賣家已取退回包裹（B2C）
		'3025' => 'refunded',           // 買家未取包裹，已退回物流中心
		'3031' => 'refunded',           // 包裹已退至指定寄件門市
		'3310' => 'refunded',           // 已退回寄件人

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
