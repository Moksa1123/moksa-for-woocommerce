<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

use Moksafowo\Modules\Shipping\Admin\BatchPrintRegistry;
use Moksafowo\Modules\Shipping\Admin\CvsStoreEditor;

defined( 'ABSPATH' ) || exit;

/**
 * 破壞性操作：更換超商取貨門市。
 *
 * prepare() 只驗證 + 描述（不變更），apply() 才真的寫入，兩者都要 manage_woocommerce。
 * 寫入走 CvsStoreEditor::write_store()，跟後台改門市同一組 meta 與同一個 sync callback。
 *
 * 刻意不做的事：
 * - 不自動重建物流單。已建單的訂單改門市後，舊標籤仍寄往舊門市，只提醒不代勞。
 * - 沒給店名／地址時一律清空，不留舊值 —— 「新編號配舊店名」會讓客服與顧客誤判。
 */
final class ChangePickupStore {

	const CAP = 'manage_woocommerce';

	/**
	 * 驗證並描述要做的變更（不執行）。
	 *
	 * @param mixed $args { order: string|int, store_id: string, store_name?: string, store_address?: string }.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}

		$args = is_array( $args ) ? $args : [];
		$ref  = isset( $args['order'] ) ? (string) $args['order'] : '';

		$order = self::resolve_order( $ref );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$match = CvsStoreEditor::match( $order );
		if ( null === $match ) {
			return new \WP_Error(
				'moksafowo_ai_not_cvs',
				sprintf(
					/* translators: %s: order number */
					__( 'Order #%s is not a convenience store pickup order, so it has no pickup store to change.', 'moksa-for-woocommerce' ),
					$order->get_order_number()
				)
			);
		}

		$store_id = self::clean_store_id( isset( $args['store_id'] ) ? (string) $args['store_id'] : '' );
		if ( is_wp_error( $store_id ) ) {
			return $store_id;
		}

		$meta   = $match['provider']['meta'];
		$before = [
			'id'      => (string) $order->get_meta( $meta['id'] ),
			'name'    => empty( $meta['name'] ) ? '' : (string) $order->get_meta( $meta['name'] ),
			'address' => empty( $meta['address'] ) ? '' : (string) $order->get_meta( $meta['address'] ),
		];

		if ( $before['id'] === $store_id ) {
			return new \WP_Error(
				'moksafowo_ai_same_store',
				sprintf(
					/* translators: 1: order number, 2: store number */
					__( 'Order #%1$s is already set to pick up at store %2$s, so there is nothing to change.', 'moksa-for-woocommerce' ),
					$order->get_order_number(),
					$store_id
				)
			);
		}

		$store_name    = isset( $args['store_name'] ) ? sanitize_text_field( (string) $args['store_name'] ) : '';
		$store_address = isset( $args['store_address'] ) ? sanitize_text_field( (string) $args['store_address'] ) : '';

		$shipments = self::count_shipments( $order, $match['method_id'] );

		$summary = sprintf(
			/* translators: 1: order number, 2: carrier label, 3: previous store, 4: new store */
			__( 'Change the pickup store on order #%1$s (%2$s) from %3$s to %4$s.', 'moksa-for-woocommerce' ),
			$order->get_order_number(),
			(string) ( $match['provider']['label'] ?? $match['key'] ),
			'' === $before['id'] ? __( '(no store set)', 'moksa-for-woocommerce' ) : trim( $before['id'] . ' ' . $before['name'] ),
			trim( $store_id . ' ' . $store_name )
		);

		if ( '' === $store_name && '' === $store_address ) {
			$summary .= ' ' . __( 'No store name or address was given, so those two fields will be cleared — leaving the old ones would show the new number with the old store name.', 'moksa-for-woocommerce' );
		}

		if ( $shipments > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: number of shipments already created */
				_n(
					'Warning: %d shipment has already been created for this order and it still points at the old store. Delete it and create it again after this change.',
					'Warning: %d shipments have already been created for this order and they still point at the old store. Delete them and create them again after this change.',
					$shipments,
					'moksa-for-woocommerce'
				),
				$shipments
			);
		}

		return [
			'order_id'      => $order->get_id(),
			'number'        => (string) $order->get_order_number(),
			'provider'      => $match['key'],
			'method_id'     => $match['method_id'],
			'before'        => $before,
			'store_id'      => $store_id,
			'store_name'    => $store_name,
			'store_address' => $store_address,
			'shipments'     => $shipments,
			'summary'       => $summary,
		];
	}

	/**
	 * 真正執行變更（使用者確認後）。
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

		// 從提議到按下確認之間，運送方式可能被改掉 —— 重驗一次，不能只信 prepare 的結果
		$match = CvsStoreEditor::match( $order );
		if ( null === $match ) {
			return new \WP_Error( 'moksafowo_ai_not_cvs', __( 'This order is no longer a convenience store pickup order, so the store was not changed.', 'moksa-for-woocommerce' ) );
		}
		if ( (string) ( $params['method_id'] ?? '' ) !== $match['method_id'] ) {
			return new \WP_Error( 'moksafowo_ai_method_changed', __( 'The shipping method on this order changed after the proposal was made, so the store was not changed. Please ask again.', 'moksa-for-woocommerce' ) );
		}

		$store_id = self::clean_store_id( (string) ( $params['store_id'] ?? '' ) );
		if ( is_wp_error( $store_id ) ) {
			return $store_id;
		}

		$before = (array) ( $params['before'] ?? [] );
		$after  = CvsStoreEditor::write_store(
			$order,
			[
				'id'      => $store_id,
				'name'    => (string) ( $params['store_name'] ?? '' ),
				'address' => (string) ( $params['store_address'] ?? '' ),
			]
		);
		if ( null === $after ) {
			return new \WP_Error( 'moksafowo_ai_write_failed', __( 'The pickup store could not be written to the order.', 'moksa-for-woocommerce' ) );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: previous store number, 2: new store number */
				__( 'Pickup store changed from %1$s to %2$s in Moksa AI. Any shipment already created still points at the old store — delete it and create a new one.', 'moksa-for-woocommerce' ),
				'' === (string) ( $before['id'] ?? '' ) ? __( '(none)', 'moksa-for-woocommerce' ) : (string) $before['id'],
				$store_id
			)
		);
		$order->save();

		// 重讀一次確認真的寫進去了（HPOS 下 meta 是另一張表）
		$fresh   = wc_get_order( $order->get_id() );
		$written = $fresh instanceof \WC_Order ? (string) $fresh->get_meta( $match['provider']['meta']['id'] ) : '';
		if ( $written !== $store_id ) {
			return new \WP_Error(
				'moksafowo_ai_verify_failed',
				sprintf(
					/* translators: 1: expected store number, 2: store number actually stored */
					__( 'The change was submitted, but the order still shows store %2$s instead of %1$s. Please check the order screen.', 'moksa-for-woocommerce' ),
					$store_id,
					'' === $written ? '(empty)' : $written
				)
			);
		}

		$message = sprintf(
			/* translators: 1: order number, 2: new store */
			__( '✅ Order #%1$s now picks up at store %2$s, and a follow-up check confirms it was saved.', 'moksa-for-woocommerce' ),
			$order->get_order_number(),
			trim( $store_id . ' ' . (string) $after['name'] )
		);

		$shipments = self::count_shipments( $order, $match['method_id'] );
		if ( $shipments > 0 ) {
			$message .= ' ' . __( 'The shipment created earlier still points at the old store — delete it on the order screen and create it again.', 'moksa-for-woocommerce' );
		}

		return $message;
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

	/**
	 * 門市編號各家格式不同（綠界 / PAYUNi 數字、速買配可能帶英數），所以只擋明顯不合理的：
	 * 去掉空白後必須是英數、長度 1–20。不做更嚴格的假設，免得擋掉合法門市。
	 *
	 * @return string|\WP_Error
	 */
	private static function clean_store_id( string $raw ) {
		$id = preg_replace( '/\s+/u', '', $raw ) ?? '';
		if ( '' === $id ) {
			return new \WP_Error( 'moksafowo_ai_no_store_id', __( 'Please give the store number to change to.', 'moksa-for-woocommerce' ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9]{1,20}$/', $id ) ) {
			return new \WP_Error(
				'moksafowo_ai_bad_store_id',
				sprintf(
					/* translators: %s: the store number that was given */
					__( 'That does not look like a store number: %s. A store number is letters and digits only.', 'moksa-for-woocommerce' ),
					$raw
				)
			);
		}
		return $id;
	}

	/**
	 * 這張訂單已經建了幾張物流單 —— 走批次列印註冊表的 record_counter，各家都已經註冊過。
	 */
	private static function count_shipments( \WC_Order $order, string $method_id ): int {
		$count = 0;
		foreach ( BatchPrintRegistry::all() as $entry ) {
			if ( ! in_array( $method_id, (array) $entry['method_ids'], true ) ) {
				continue;
			}
			if ( is_callable( $entry['record_counter'] ?? null ) ) {
				$count += (int) call_user_func( $entry['record_counter'], $order );
			}
		}
		return $count;
	}
}
