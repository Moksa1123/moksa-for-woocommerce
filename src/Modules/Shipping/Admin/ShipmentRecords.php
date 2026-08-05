<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * 跨物流商的物流單記錄門面。
 *
 * 四家的儲存形狀不一樣（綠界 / PAYUNi / 速買配是 records 陣列各自欄位不同；
 * 藍新根本沒有陣列，只有單鍵），所以列出與刪除都由各家自己實作，
 * 透過批次列印註冊表既有的描述子多掛兩個可選 callable：
 *
 *   'record_lister'  => fn( \WC_Order ): array<int, array{id:string, tracking?:string,
 *                          created_at?:string, temp?:string, note?:string}>
 *   'record_deleter' => fn( \WC_Order, string $id ): bool
 *
 * 沒掛的物流商就是不支援，呼叫端要能講清楚而不是默默失敗。
 */
final class ShipmentRecords {

	/**
	 * 列出這張訂單所有物流單記錄（跨物流商）。
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function all( \WC_Order $order ): array {
		$method_ids = self::order_method_ids( $order );
		$out        = [];

		foreach ( BatchPrintRegistry::all() as $entry ) {
			if ( ! array_intersect( $method_ids, (array) $entry['method_ids'] ) ) {
				continue;
			}
			if ( ! is_callable( $entry['record_lister'] ?? null ) ) {
				continue;
			}
			foreach ( (array) call_user_func( $entry['record_lister'], $order ) as $record ) {
				if ( empty( $record['id'] ) ) {
					continue;
				}
				$out[] = array_merge(
					[
						'provider'       => $entry['key'],
						'provider_label' => $entry['label'],
						'can_delete'     => is_callable( $entry['record_deleter'] ?? null ),
					],
					(array) $record
				);
			}
		}

		return $out;
	}

	/**
	 * 刪掉一筆記錄。回傳 [ ok, message ]，讓呼叫端能分辨「找不到」與「這家不支援」。
	 *
	 * @return array{ok:bool, message:string, provider:string}
	 */
	public static function delete( \WC_Order $order, string $record_id ): array {
		$record_id = trim( $record_id );
		if ( '' === $record_id ) {
			return [
				'ok'       => false,
				'message'  => __( 'No shipment number was given.', 'moksa-for-woocommerce' ),
				'provider' => '',
			];
		}

		foreach ( self::all( $order ) as $record ) {
			if ( (string) $record['id'] !== $record_id ) {
				continue;
			}
			$entry = BatchPrintRegistry::get( (string) $record['provider'] );
			if ( null === $entry || ! is_callable( $entry['record_deleter'] ?? null ) ) {
				return [
					'ok'       => false,
					/* translators: %s: carrier name */
					'message'  => sprintf( __( 'Shipment records for %s cannot be deleted from here yet.', 'moksa-for-woocommerce' ), (string) $record['provider_label'] ),
					'provider' => (string) $record['provider'],
				];
			}
			$ok = (bool) call_user_func( $entry['record_deleter'], $order, $record_id );
			return [
				'ok'       => $ok,
				'message'  => $ok ? '' : __( 'That shipment record could not be deleted.', 'moksa-for-woocommerce' ),
				'provider' => (string) $record['provider'],
			];
		}

		return [
			'ok'       => false,
			/* translators: %s: shipment number */
			'message'  => sprintf( __( 'This order has no shipment numbered %s.', 'moksa-for-woocommerce' ), $record_id ),
			'provider' => '',
		];
	}

	/**
	 * @return string[]
	 */
	private static function order_method_ids( \WC_Order $order ): array {
		$ids = [];
		foreach ( $order->get_shipping_methods() as $item ) {
			$ids[] = (string) $item->get_method_id();
		}
		return $ids;
	}
}
