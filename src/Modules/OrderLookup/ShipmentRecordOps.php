<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

use Moksafowo\Modules\Shipping\Admin\ShipmentRecords;

defined( 'ABSPATH' ) || exit;

/**
 * 物流單記錄的列出（唯讀）與刪除（破壞性）。
 *
 * 刪除只刪站上的記錄，**不會**通知物流商作廢已建立的單 —— 那是各家的作廢 API，
 * 每家規則不同（有的建了就不能撤），所以這裡不假裝做得到。
 * 用途是「改門市／改地址後要重新建單」，得先把舊記錄清掉才建得了新的。
 */
final class ShipmentRecordOps {

	const CAP = 'manage_woocommerce';

	/**
	 * 列出訂單的所有物流單記錄（唯讀）。
	 *
	 * @param mixed $args { order: string|int }.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function list_records( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}

		$order = self::resolve_order( is_array( $args ) && isset( $args['order'] ) ? (string) $args['order'] : '' );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$records = ShipmentRecords::all( $order );
		$rows    = [];
		foreach ( $records as $r ) {
			$rows[] = [
				'shipment_number' => (string) $r['id'],
				'carrier'         => (string) $r['provider_label'],
				'tracking'        => trim( (string) ( $r['tracking'] ?? '' ) ),
				'created_at'      => (string) ( $r['created_at'] ?? '' ),
				'status'          => (string) ( $r['note'] ?? '' ),
				'can_delete'      => (bool) $r['can_delete'],
			];
		}

		return [
			'order_id' => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'count'    => count( $rows ),
			'records'  => $rows,
		];
	}

	/**
	 * 驗證並描述要刪掉哪一筆（不執行）。
	 *
	 * @param mixed $args { order: string|int, shipment_number: string }.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}

		$args  = is_array( $args ) ? $args : [];
		$order = self::resolve_order( isset( $args['order'] ) ? (string) $args['order'] : '' );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$wanted  = trim( isset( $args['shipment_number'] ) ? (string) $args['shipment_number'] : '' );
		$records = ShipmentRecords::all( $order );

		if ( empty( $records ) ) {
			return new \WP_Error(
				'moksafowo_ai_no_records',
				sprintf(
					/* translators: %s: order number */
					__( 'Order #%s has no shipments to delete.', 'moksa-for-woocommerce' ),
					$order->get_order_number()
				)
			);
		}

		// 沒指定又只有一筆就用那筆；有多筆一定要指定，不猜
		if ( '' === $wanted ) {
			if ( count( $records ) > 1 ) {
				$list = [];
				foreach ( $records as $r ) {
					$list[] = (string) $r['id'];
				}
				return new \WP_Error(
					'moksafowo_ai_ambiguous',
					sprintf(
						/* translators: 1: order number, 2: comma separated shipment numbers */
						__( 'Order #%1$s has more than one shipment (%2$s). Say which shipment number to delete.', 'moksa-for-woocommerce' ),
						$order->get_order_number(),
						implode( ', ', $list )
					)
				);
			}
			$wanted = (string) $records[0]['id'];
		}

		$target = null;
		foreach ( $records as $r ) {
			if ( (string) $r['id'] === $wanted ) {
				$target = $r;
				break;
			}
		}
		if ( null === $target ) {
			return new \WP_Error(
				'moksafowo_ai_no_record',
				sprintf(
					/* translators: 1: shipment number, 2: order number */
					__( 'Shipment %1$s does not belong to order #%2$s.', 'moksa-for-woocommerce' ),
					$wanted,
					$order->get_order_number()
				)
			);
		}
		if ( empty( $target['can_delete'] ) ) {
			return new \WP_Error(
				'moksafowo_ai_cannot_delete',
				sprintf(
					/* translators: %s: carrier name */
					__( 'Shipment records for %s cannot be deleted from here yet.', 'moksa-for-woocommerce' ),
					(string) $target['provider_label']
				)
			);
		}

		return [
			'order_id'        => $order->get_id(),
			'number'          => (string) $order->get_order_number(),
			'shipment_number' => $wanted,
			'carrier'         => (string) $target['provider_label'],
			'remaining'       => count( $records ) - 1,
			'summary'         => sprintf(
				/* translators: 1: shipment number, 2: carrier, 3: order number */
				__( 'Delete shipment %1$s (%2$s) from order #%3$s. This only removes the record on this site — it does not cancel the booking with the carrier, so do not delete it if the parcel is already on its way. Delete it when you need to create the shipment again, for example after changing the pickup store.', 'moksa-for-woocommerce' ),
				$wanted,
				(string) $target['provider_label'],
				$order->get_order_number()
			),
		];
	}

	/**
	 * 真正刪除（使用者確認後）。
	 *
	 * @param array<string,mixed> $params prepare() 的回傳值。
	 * @return string|\WP_Error
	 */
	public static function apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}

		$order = wc_get_order( (int) ( $params['order_id'] ?? 0 ) );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'moksafowo_ai_no_order', __( 'The order could not be found.', 'moksa-for-woocommerce' ) );
		}

		$number = trim( (string) ( $params['shipment_number'] ?? '' ) );
		if ( '' === $number ) {
			return new \WP_Error( 'moksafowo_ai_no_record', __( 'No shipment number was given.', 'moksa-for-woocommerce' ) );
		}

		$result = ShipmentRecords::delete( $order, $number );
		if ( ! $result['ok'] ) {
			return new \WP_Error( 'moksafowo_ai_delete_failed', $result['message'] );
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: shipment number */
				__( 'Shipment %s was removed from this order in Moksa AI. The booking with the carrier was not cancelled.', 'moksa-for-woocommerce' ),
				$number
			)
		);
		$order->save();

		// 重讀確認真的不在了
		$fresh = wc_get_order( $order->get_id() );
		$left  = $fresh instanceof \WC_Order ? ShipmentRecords::all( $fresh ) : [];
		foreach ( $left as $r ) {
			if ( (string) $r['id'] === $number ) {
				return new \WP_Error( 'moksafowo_ai_verify_failed', __( 'The delete was submitted, but the shipment is still on the order. Please check the order screen.', 'moksa-for-woocommerce' ) );
			}
		}

		return sprintf(
			/* translators: 1: shipment number, 2: order number, 3: how many shipments remain */
			__( '✅ Shipment %1$s was removed from order #%2$s (%3$d left). You can create the shipment again now.', 'moksa-for-woocommerce' ),
			$number,
			$order->get_order_number(),
			count( $left )
		);
	}

	/**
	 * @return \WC_Order|\WP_Error
	 */
	private static function resolve_order( string $ref ) {
		$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			/* translators: %s: order reference the user gave */
			return new \WP_Error( 'moksafowo_ai_no_order', sprintf( __( 'Order not found: %s.', 'moksa-for-woocommerce' ), $ref ) );
		}
		return $order;
	}
}
