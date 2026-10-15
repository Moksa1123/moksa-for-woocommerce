<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * 物流通知能不能改訂單狀態。各家物流的通知都走這裡，規則才一致：
 * 出貨進度不倒退（晚到或重送的「已出貨」不能把已完成的單打回去），
 * 已退款、已取消、失敗的訂單只能人工改，物流通知只留備註。
 */
final class StatusGuard {

	private const PROGRESS = [
		'moksa-shipped'     => 1,
		'moksa-cvs-arrived' => 2,
		'completed'         => 3,
	];

	private const TERMINAL = [ 'refunded', 'cancelled', 'failed' ];

	public static function is_terminal( string $current ): bool {
		return in_array( $current, self::TERMINAL, true );
	}

	public static function is_regression( string $current, string $target ): bool {
		return isset( self::PROGRESS[ $current ], self::PROGRESS[ $target ] )
			&& self::PROGRESS[ $current ] > self::PROGRESS[ $target ];
	}

	public static function may_transition( string $current, string $target ): bool {
		return $current !== $target && ! self::is_terminal( $current ) && ! self::is_regression( $current, $target );
	}

	private function __construct() {}
}
