<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Webhook;

use Moksafowo\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * 貨態補查排程。
 *
 * 為什麼需要：整個外掛的訂單狀態轉換都靠物流商主動打 IPN。IPN 只要掉一次
 * （網路瞬斷、回呼網址設錯、站台擋外部 POST、對方重送次數用盡），那筆訂單就
 * 永遠停在舊狀態 —— 沒有重試、沒有補查，商家只能手動改。實際發生過：包裹
 * 8/21 就到店了，訂單到月底還掛在「已出貨」。
 *
 * 做法：每小時撈出「卡在轉運中狀態、且超過閾值沒更新」的訂單，向物流商查
 * 權威貨態，再走跟 IPN 同一條 StatusMapper。補查只是備援，IPN 仍是主要路徑。
 *
 * 安全性：只讀取物流商的查詢 API，不送出任何會改變對方狀態的指令。
 */
final class StatusReconciler {

	public const HOOK      = 'moksafowo_shipping_reconcile_statuses';
	public const OPT_ON    = 'moksafowo_shipping_reconcile_enabled';
	public const OPT_HOURS = 'moksafowo_shipping_reconcile_min_hours';

	/** 一次最多查幾筆 —— 避免單次排程打爆物流商 API 或跑到逾時。 */
	private const BATCH = 30;

	/** 只有停在這些狀態的訂單才需要補查；已完成 / 已取消的不再動。 */
	private const STUCK_STATUSES = [ 'processing', 'moksa-shipped', 'moksa-cvs-arrived' ];

	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
	}

	public static function enabled(): bool {
		return 'yes' === get_option( self::OPT_ON, 'yes' );
	}

	/**
	 * 訂單至少幾小時沒更新才值得補查。設太短會在 IPN 正常送達前就搶著查，
	 * 白白消耗物流商 API 配額；預設 6 小時。
	 */
	public static function min_hours(): int {
		return max( 1, (int) get_option( self::OPT_HOURS, 6 ) );
	}

	public static function maybe_schedule(): void {
		if ( ! self::enabled() ) {
			self::unschedule();
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK, [], 'moksa-for-woocommerce' ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, [], 'moksa-for-woocommerce' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], 'moksa-for-woocommerce' );
		}
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

	/**
	 * 各物流模組把自己的補查器掛進來：
	 *   add_filter( 'moksafowo_shipping_reconcilers', fn( $r ) => $r + [ 'ecpay' => [ Foo::class, 'reconcile' ] ] );
	 *
	 * callable 簽章：( \WC_Order $order ): bool —— 有送出查詢就回 true。
	 *
	 * @return array<string,callable>
	 */
	private static function reconcilers(): array {
		$out = [];
		foreach ( (array) apply_filters( 'moksafowo_shipping_reconcilers', [] ) as $slug => $cb ) {
			if ( is_callable( $cb ) ) {
				$out[ (string) $slug ] = $cb;
			}
		}
		return $out;
	}

	/**
	 * 每輪挑單的候選池上限。一輪只「查」BATCH 筆，但要多撈一些才能跳過剛查過的。
	 */
	private const POOL = 300;

	/** 只追建立 30 天內的訂單 —— 再舊的多半是沒出貨的單，查了也是白耗物流商配額。 */
	private const MAX_AGE_DAYS = 30;

	private const THROTTLE_PREFIX = 'moksafowo_ship_rc_';

	public static function run(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$reconcilers = self::reconcilers();
		if ( empty( $reconcilers ) ) {
			return;
		}

		$cutoff = time() - ( self::min_hours() * HOUR_IN_SECONDS );
		$ids    = wc_get_orders(
			[
				'limit'         => self::POOL,
				'type'          => 'shop_order',
				'status'        => self::STUCK_STATUSES,
				'orderby'       => 'modified',
				'order'         => 'ASC',
				// 一定要傳 Unix timestamp：傳 'Y-m-d H:i:s' 字串時 WC 會用網站時區解讀，
				// 而這裡算的是 UTC，台灣站等於門檻被往前推 8 小時（6 小時變 14 小時）。
				'date_modified' => '<' . $cutoff,
				'date_created'  => '>' . ( time() - self::MAX_AGE_DAYS * DAY_IN_SECONDS ),
				'return'        => 'ids',
			]
		);
		if ( empty( $ids ) ) {
			return;
		}

		// 「剛查過」記在暫存，不寫進訂單。寫訂單會觸發 woocommerce_update_order，
		// 接了 webhook / n8n 的站每小時每筆卡住的單都被叫醒一次。
		// 也因為不再碰訂單，date_modified 不會前進 —— 挑單若只取最舊 30 筆，
		// 每輪都會是同一批，第 31 筆以後永遠輪不到；所以多撈、跳過節流中的，湊滿為止。
		$throttle = self::min_hours() * HOUR_IN_SECONDS;
		$checked  = 0;
		foreach ( $ids as $id ) {
			if ( $checked >= self::BATCH ) {
				break;
			}
			if ( false !== get_transient( self::THROTTLE_PREFIX . $id ) ) {
				continue;
			}
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			foreach ( $reconcilers as $slug => $cb ) {
				try {
					if ( $cb( $order ) ) {
						++$checked;
						set_transient( self::THROTTLE_PREFIX . $id, time(), $throttle );
						break;
					}
				} catch ( \Throwable $e ) {
					// 一筆訂單查失敗不能讓整批停擺；也記節流，免得每輪重撞同一筆壞單。
					set_transient( self::THROTTLE_PREFIX . $id, time(), $throttle );
					Logger::info(
						'shipping-reconcile',
						'reconciler threw',
						[
							'provider' => $slug,
							'order_id' => $id,
							'msg'      => $e->getMessage(),
						]
					);
				}
			}
		}

		if ( $checked > 0 ) {
			Logger::info(
				'shipping-reconcile',
				'batch done',
				[
					'pool'    => count( $ids ),
					'queried' => $checked,
				]
			);
		}
	}
}
