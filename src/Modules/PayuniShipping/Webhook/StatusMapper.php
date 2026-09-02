<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Webhook;

use Moksafowo\Modules\Shipping\Webhook\AbstractStatusMapper;

defined( 'ABSPATH' ) || exit;

final class StatusMapper extends AbstractStatusMapper {

	private const MAP = [
		'92' => 'moksa-shipped',     // 出貨
		'98' => 'moksa-shipped',
		'22' => 'moksa-shipped',
		'31' => 'moksa-shipped',
		'32' => 'moksa-cvs-arrived', // 到店
		'11' => 'completed',         // 取貨完成
		'33' => 'failed',            // 配送異常
		'43' => 'failed',
		'44' => 'failed',
		'46' => 'failed',
		// 退貨群組 —— 超商取件退回即「未取件」，不自動轉已退費（退款由商家決定）。
		'51' => 'moksa-unclaimed',
		'52' => 'moksa-unclaimed',
		'53' => 'moksa-unclaimed',
		'55' => 'moksa-unclaimed',
		'56' => 'moksa-unclaimed',
		'82' => 'moksa-unclaimed',
		'81' => 'moksa-store-closed', // 門市暫歇
	];

	public static function init(): void {
		add_action( 'moksafowo_payuni_update_shipping_order_status', [ __CLASS__, 'handle_legacy_action' ], 20, 3 );
	}

	public static function handle_legacy_action( \WC_Order $order, string $code, string $desc ): void {
		( new self() )->handle_status_received( $order, (string) $code, (string) $desc );
	}

	protected function provider_slug(): string {
		return 'payuni';
	}

	protected function code_map(): array {
		return self::MAP;
	}
}
