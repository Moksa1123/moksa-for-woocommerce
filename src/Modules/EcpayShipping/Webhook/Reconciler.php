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

		// 節流由 StatusReconciler 統一記在暫存；這裡沒變化就完全不碰訂單。
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
			$msg  = (string) $result['msg'];
			// 查詢 API 不回說明文字，只能比碼。
			if ( $code === (string) $order->get_meta( Keys::ECPAY_LOGISTIC_RTN_CODE ) ) {
				continue; // 貨態沒變，不重複寫備註。
			}

			$order->update_meta_data( Keys::ECPAY_LOGISTIC_RTN_CODE, $code );
			if ( '' !== $msg ) {
				$order->update_meta_data( Keys::ECPAY_LOGISTIC_RTN_MSG, $msg );
				$note = sprintf(
					/* translators: 1: status message, 2: status code */
					__( 'ECPay shipping status found by follow-up check: %1$s (status code %2$s)', 'moksa-for-woocommerce' ),
					$msg,
					$code
				);
			} else {
				$order->delete_meta_data( Keys::ECPAY_LOGISTIC_RTN_MSG );
				$note = sprintf(
					/* translators: %s: status code */
					__( 'ECPay shipping status found by follow-up check: status code %s', 'moksa-for-woocommerce' ),
					$code
				);
			}
			$order->add_order_note( $note );
			$order->save();

			// 走跟 IPN 完全相同的對應與轉換路徑。
			do_action( 'moksafowo_ecpay_shipping_status_received', $order, $code, (string) $result['msg'] );
		}

		return $queried;
	}
}
