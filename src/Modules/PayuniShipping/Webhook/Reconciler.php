<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Webhook;

use Moksafowo\Modules\PayuniShipping\Api\ShippingRequest;
use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * PAYUNi 貨態補查 —— IPN 沒送達時的備援。
 *
 * 復用既有的 ShippingRequest::query_order()（後台「查詢」鈕也是打這支），
 * 但補上它缺的最後一步：把查回來的 ShipStatus 丟進 StatusMapper。手動查詢
 * 原本只寫備註與 meta，不會轉訂單狀態。
 */
final class Reconciler {

	private const LAST_QUERY = '_moksafowo_payuni_reconcile_last';

	public static function init(): void {
		add_filter(
			'moksafowo_shipping_reconcilers',
			static function ( array $r ): array {
				$r['payuni'] = [ __CLASS__, 'reconcile' ];
				return $r;
			}
		);
	}

	public static function reconcile( \WC_Order $order ): bool {
		if ( '' === (string) $order->get_meta( OrderMeta::ShipTradeNo ) ) {
			return false;
		}

		$last = (int) $order->get_meta( self::LAST_QUERY );
		if ( $last > 0 && ( time() - $last ) < HOUR_IN_SECONDS ) {
			return true;
		}

		$response = ShippingRequest::query_order( $order );
		$order->update_meta_data( self::LAST_QUERY, (string) time() );
		$order->save();

		if ( is_wp_error( $response ) ) {
			PayuniShipping::log( 'reconcile query failed: ' . $response->get_error_message() );
			return true;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! is_object( $body ) || empty( $body->EncryptInfo ) ) {
			return true;
		}

		$info = PayuniShipping::decrypt( $body->EncryptInfo );
		if ( ! is_array( $info ) || 'SUCCESS' !== ( $info['Status'] ?? '' ) ) {
			return true;
		}

		$code = (string) ( $info['ShipStatus'] ?? '' );
		if ( '' === $code || '-' === $code ) {
			return true;
		}

		$seen = (string) $order->get_meta( OrderMeta::ShipStatus );
		if ( $code === $seen ) {
			return true; // 貨態沒變。
		}

		$desc = (string) ( $info['ShipStatusDesc'] ?? '' );
		$order->update_meta_data( OrderMeta::ShipStatus, $code );
		if ( '' !== $desc ) {
			$order->update_meta_data( OrderMeta::ShipStatusDesc, $desc );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: status description, 2: status code */
				__( 'PAYUNi shipping status found by follow-up check: %1$s (status code %2$s)', 'moksa-for-woocommerce' ),
				$desc,
				$code
			)
		);
		$order->save();

		// 走跟 IPN 完全相同的對應與轉換路徑。
		do_action( 'moksafowo_payuni_update_shipping_order_status', $order, $code, $desc );
		return true;
	}
}
