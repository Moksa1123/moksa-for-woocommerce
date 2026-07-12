<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 營收 / 訂單統計(唯讀,看生意用)。某期間的訂單數、已付款營收、各狀態分布、平均客單價。
 * 不給日期則預設本月(伺服器當地時間)。走 wc_get_orders(日期區間)即時計算。
 */
final class SalesSummary {

	const MAX = 2000;

	/**
	 * @param mixed $input { date_from?: string, date_to?: string }(YYYY-MM-DD)。
	 * @return array<string,mixed>
	 */
	public static function execute( $input ): array {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return array();
		}
		$input  = is_array( $input ) ? $input : array();
		$period = isset( $input['period'] ) ? sanitize_key( (string) $input['period'] ) : 'this_month';

		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- 刻意取站台時區的本地時戳：下方 today / this_month / last_month 等報表區間需以「站台當地日期」計算，改 UTC time() 會跨日錯位。
		$now   = (int) current_time( 'timestamp' );
		$today = gmdate( 'Y-m-d', $now );
		$cfrom = isset( $input['date_from'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $input['date_from'] ) : '';
		$cto   = isset( $input['date_to'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $input['date_to'] ) : '';

		switch ( $period ) {
			case 'today':
				$from = $today;
				$to   = $today;
				break;
			case 'yesterday':
				$from = gmdate( 'Y-m-d', $now - DAY_IN_SECONDS );
				$to   = $from;
				break;
			case 'last_month':
				$from = gmdate( 'Y-m-01', (int) strtotime( 'first day of last month', $now ) );
				$to   = gmdate( 'Y-m-t', (int) strtotime( 'last day of last month', $now ) );
				break;
			case 'this_year':
				$from = gmdate( 'Y-01-01', $now );
				$to   = $today;
				break;
			case 'custom':
				$from = '' !== $cfrom ? $cfrom : gmdate( 'Y-m-01', $now );
				$to   = '' !== $cto ? $cto : $today;
				break;
			case 'this_month':
			default:
				$from = ( '' !== $cfrom ) ? $cfrom : gmdate( 'Y-m-01', $now );
				$to   = ( '' !== $cto ) ? $cto : $today;
				break;
		}

		$orders = wc_get_orders(
			array(
				'date_created' => $from . '...' . $to,
				'limit'        => self::MAX,
				'return'       => 'objects',
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);

		$count     = 0;
		$paid_cnt  = 0;
		$revenue   = 0.0;
		$breakdown = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			++$count;
			$label               = wc_get_order_status_name( $order->get_status() );
			$breakdown[ $label ] = ( $breakdown[ $label ] ?? 0 ) + 1;
			if ( $order->is_paid() ) {
				++$paid_cnt;
				$revenue += (float) $order->get_total();
			}
		}

		$avg = $paid_cnt > 0 ? $revenue / $paid_cnt : 0.0;

		$breakdown_rows = array();
		foreach ( $breakdown as $label => $n ) {
			$breakdown_rows[] = array(
				'status' => $label,
				'count'  => $n,
			);
		}

		return array(
			'date_from'    => $from,
			'date_to'      => $to,
			'total_orders' => $count,
			'paid_orders'  => $paid_cnt,
			'revenue'      => self::money( $revenue ),
			'avg_order'    => self::money( $avg ),
			'by_status'    => $breakdown_rows,
			'truncated'    => $count >= self::MAX,
		);
	}

	private static function money( float $v ): string {
		return html_entity_decode( wp_strip_all_tags( (string) wc_price( $v ) ), ENT_QUOTES, 'UTF-8' );
	}
}
