<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Webhook;

use MoksaWeb\Mowc\Modules\Shipping\Webhook\AbstractStatusMapper;

defined( 'ABSPATH' ) || exit;

final class StatusMapper extends AbstractStatusMapper {

	private const MAP = [
		// 出貨／配送中
		'92' => 'moksa-shipped',
		'98' => 'moksa-shipped',
		'22' => 'moksa-shipped',
		'31' => 'moksa-shipped',

		// 到店
		'32' => 'moksa-cvs-arrived',

		// 取貨完成 → WC 既有 completed
		'11' => 'completed',

		// 配送異常 → WC 既有 failed
		'33' => 'failed',
		'43' => 'failed',
		'44' => 'failed',
		'46' => 'failed',

		// 退貨 → WC 既有 refunded
		'51' => 'refunded',
		'52' => 'refunded',
		'53' => 'refunded',
		'55' => 'refunded',
		'56' => 'refunded',
		'82' => 'refunded',

		// 門市暫歇，催顧客重選
		'81' => 'moksa-store-closed',
	];

	public static function init(): void {
		// ShippingResponse 既有 do_action('moksafowo_payuni_update_shipping_order_status') 接這個。
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
