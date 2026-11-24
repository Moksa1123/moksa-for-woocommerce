<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * 等 Action Scheduler 可以排程了再執行。
 *
 * 模組在 woocommerce_init 開機，那是 init 優先序 0；Action Scheduler 到 init 優先序 1 才初始化資料儲存。
 * 用 did_action( 'init' ) 判斷會在 init 進行中就當成「已經可以」，排程被擋下（_doing_it_wrong），
 * 舊站的背景搬遷因此從沒排進去過。改看 Action Scheduler 自己的狀態，還沒好就等 action_scheduler_init。
 */
final class Scheduler {

	public static function when_ready( callable $callback ): void {
		if ( class_exists( '\ActionScheduler', false ) && \ActionScheduler::is_initialized() ) {
			$callback();
			return;
		}
		add_action( 'action_scheduler_init', $callback );
	}

	private function __construct() {}
}
