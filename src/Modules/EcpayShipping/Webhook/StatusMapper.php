<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Webhook;

use MoksaWeb\Mowc\Modules\Shipping\Webhook\AbstractStatusMapper;

defined( 'ABSPATH' ) || exit;

final class StatusMapper extends AbstractStatusMapper {

	private const MAP = [
		// 賣家已寄件 / 配送中 / 上架中 — 對應「已出貨」
		'2030' => 'mo-shipped',  // 黑貓收件成功
		'2068' => 'mo-shipped',  // 賣家已到門市寄件（CVS C2C）
		'3001' => 'mo-shipped',  // 新增貨件
		'3006' => 'mo-shipped',  // 上架
		'3023' => 'mo-shipped',  // 已配送中
		'3024' => 'mo-shipped',  // 店到店上架
		'3032' => 'mo-shipped',  // 轉運中心收貨
		'3119' => 'mo-shipped',  // 中華郵政已收件
		'3301' => 'mo-shipped',  // 店到店收件

		// 已到店待取 — 對應 mo-cvs-arrived
		'2067' => 'mo-cvs-arrived', // 7-11 已到店
		'3018' => 'mo-cvs-arrived', // 全家 已到店
		'3019' => 'mo-cvs-arrived', // 萊爾富 已到店

		// 顧客已取貨 / 已配達 — 完結
		'3022' => 'completed', // 出貨成功（已取貨）
		'5002' => 'completed', // 黑貓已配達

		// 門市關轉 — 對應 mo-store-closed
		'3026' => 'mo-store-closed',

		// 退回 / 失敗
		'3027' => 'mo-failed',  // 7-11 已退貨
		'3028' => 'mo-failed',
		'2003' => 'failed',
	];

	public static function init(): void {
		add_action( 'mo_ecpay_shipping_status_received', [ __CLASS__, 'handle_legacy_action' ], 20, 3 );
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
