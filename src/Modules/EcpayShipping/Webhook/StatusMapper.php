<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Webhook;

use MoksaWeb\Mowc\Modules\Shipping\Webhook\AbstractStatusMapper;

defined( 'ABSPATH' ) || exit;

final class StatusMapper extends AbstractStatusMapper {

	private const MAP = [
		// 賣家已寄件 / 配送中 / 上架中 — 對應「已出貨」
		'2030' => 'moksa-shipped',  // 黑貓收件成功
		'2068' => 'moksa-shipped',  // 賣家已到門市寄件（CVS C2C）
		'3001' => 'moksa-shipped',  // 新增貨件
		'3006' => 'moksa-shipped',  // 上架
		'3023' => 'moksa-shipped',  // 已配送中
		'3024' => 'moksa-shipped',  // 店到店上架
		'3032' => 'moksa-shipped',  // 轉運中心收貨
		'3119' => 'moksa-shipped',  // 中華郵政已收件
		'3301' => 'moksa-shipped',  // 店到店收件

		// 已到店待取 — 對應 moksa-cvs-arrived
		'2067' => 'moksa-cvs-arrived', // 7-11 已到店
		'3018' => 'moksa-cvs-arrived', // 全家 已到店
		'3019' => 'moksa-cvs-arrived', // 萊爾富 已到店

		// 顧客已取貨 / 已配達 — 完結
		'3022' => 'completed', // 出貨成功（已取貨）
		'5002' => 'completed', // 黑貓已配達

		// 門市關轉 — 對應 moksa-store-closed
		'3026' => 'moksa-store-closed',

		// 退回 / 失敗
		'3027' => 'moksafowo-failed',  // 7-11 已退貨
		'3028' => 'moksafowo-failed',
		'2003' => 'failed',
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
