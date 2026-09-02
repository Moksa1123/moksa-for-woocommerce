<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Webhook;

use Moksafowo\Modules\EcpayShipping\Api\Helper;
use Moksafowo\Modules\EcpayShipping\Operations\CreateOrder;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 綠界貨態補查 —— IPN 沒送達時的備援。
 *
 * 走 Helper/QueryLogisticsTradeInfo/V2 拿權威貨態，再交給跟 IPN 同一個
 * StatusMapper，所以對應規則只有一份、不會兩邊漂移。
 */
final class Reconciler {

	private const LAST_QUERY = '_moksafowo_ecpay_reconcile_last';

	public static function init(): void {
		add_filter(
			'moksafowo_shipping_reconcilers',
			static function ( array $r ): array {
				$r['ecpay'] = [ __CLASS__, 'reconcile' ];
				return $r;
			}
		);
	}

	/**
	 * @return bool 這筆訂單是不是由綠界處理（有送出查詢就 true，讓上層不要再問別家）。
	 */
	public static function reconcile( \WC_Order $order ): bool {
		$records = CreateOrder::get_records( $order );
		if ( empty( $records ) ) {
			return false;
		}

		// 同一筆訂單一小時內不重複查（排程每小時跑，訂單可能連續多輪都符合條件）。
		$last = (int) $order->get_meta( self::LAST_QUERY );
		if ( $last > 0 && ( time() - $last ) < HOUR_IN_SECONDS ) {
			return true;
		}

		$queried = false;
		foreach ( $records as $r ) {
			$logistics_id = (string) ( $r['id'] ?? '' );
			$subtype      = (string) ( $r['subtype'] ?? 'UNIMARTC2C' );
			if ( '' === $logistics_id ) {
				continue;
			}

			$result  = Helper::query_logistics_trade_info( $logistics_id, $subtype );
			$queried = true;
			if ( empty( $result['ok'] ) ) {
				Helper::log(
					'reconcile query failed',
					[
						'order_id'     => $order->get_id(),
						'logistics_id' => $logistics_id,
						'msg'          => $result['msg'],
					]
				);
				continue;
			}

			$code = (string) $result['code'];
			$seen = (string) $order->get_meta( Keys::ECPAY_LOGISTIC_RTN_CODE );
			if ( $code === $seen ) {
				continue; // 貨態沒變，不重複寫備註。
			}

			$order->update_meta_data( Keys::ECPAY_LOGISTIC_RTN_CODE, $code );
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_RTN_MSG, (string) $result['msg'] );
			$order->add_order_note(
				sprintf(
					/* translators: 1: status message, 2: status code */
					__( 'ECPay shipping status found by follow-up check: %1$s (status code %2$s)', 'moksa-for-woocommerce' ),
					(string) $result['msg'],
					$code
				)
			);
			$order->save();

			// 走跟 IPN 完全相同的對應與轉換路徑。
			do_action( 'moksafowo_ecpay_shipping_status_received', $order, $code, (string) $result['msg'] );
		}

		if ( $queried ) {
			$order->update_meta_data( self::LAST_QUERY, (string) time() );
			$order->save();
		}
		return $queried;
	}
}
