<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 破壞性操作:批次更改多筆訂單狀態。
 *
 * 與 UpdateOrderStatus 同樣走「prepare 只驗證描述 / apply 才執行」的人工確認關卡,
 * 差別在一次處理多筆,apply 逐筆執行並回報成功 / 失敗(partial success)。
 */
final class BatchUpdateOrderStatus {

	const CAP = 'manage_woocommerce';
	const MAX = 50;

	/**
	 * 驗證並描述要做的批次變更(不執行)。
	 *
	 * @param mixed $args { orders: array|string, status: string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}

		$status = is_array( $args ) && isset( $args['status'] ) ? str_replace( 'wc-', '', sanitize_key( (string) $args['status'] ) ) : '';
		$refs   = self::normalize_refs( is_array( $args ) ? ( $args['orders'] ?? '' ) : '' );

		if ( empty( $refs ) ) {
			return new \WP_Error( 'moksafowo_ai_no_orders', __( '沒有指定要變更的訂單。', 'mo-ectools' ) );
		}

		$statuses = wc_get_order_statuses();
		$to_key   = 'wc-' . $status;
		if ( ! isset( $statuses[ $to_key ] ) ) {
			/* translators: %s: status slug the user gave */
			return new \WP_Error( 'moksafowo_ai_bad_status', sprintf( __( '無效的訂單狀態:%s。', 'mo-ectools' ), $status ) );
		}

		$found   = array();
		$invalid = array();
		foreach ( $refs as $ref ) {
			$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
			$order = $id ? wc_get_order( $id ) : false;
			if ( ! $order || 'shop_order' !== $order->get_type() ) {
				$invalid[] = $ref;
				continue;
			}
			$found[ $order->get_id() ] = array(
				'order_id'   => $order->get_id(),
				'number'     => (string) $order->get_order_number(),
				'from_label' => wc_get_order_status_name( $order->get_status() ),
			);
		}

		if ( empty( $found ) ) {
			return new \WP_Error( 'moksafowo_ai_no_orders', __( '找不到任何有效的訂單。', 'mo-ectools' ) );
		}

		$orders  = array_values( $found );
		$numbers = implode( ' ', array_map( static fn( $o ) => '#' . $o['number'], $orders ) );
		$summary = sprintf(
			/* translators: 1: order count, 2: order numbers, 3: target status */
			__( '將 %1$d 筆訂單(%2$s)的狀態改為「%3$s」。', 'mo-ectools' ),
			count( $orders ),
			$numbers,
			$statuses[ $to_key ]
		);
		if ( ! empty( $invalid ) ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: invalid order references */
				__( '(找不到並略過:%s)', 'mo-ectools' ),
				implode( '、', $invalid )
			);
		}

		return array(
			'orders'   => $orders,
			'to_slug'  => $status,
			'to_label' => $statuses[ $to_key ],
			'invalid'  => $invalid,
			'summary'  => $summary,
		);
	}

	/**
	 * 真正逐筆執行批次變更(使用者確認後)。
	 *
	 * @param array<string,mixed> $params prepare() 的回傳值。
	 * @return string|\WP_Error
	 */
	public static function apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}

		$to       = (string) ( $params['to_slug'] ?? '' );
		$statuses = wc_get_order_statuses();
		if ( ! isset( $statuses[ 'wc-' . $to ] ) ) {
			return new \WP_Error( 'moksafowo_ai_bad_status', __( '無效的訂單狀態。', 'mo-ectools' ) );
		}

		$orders = is_array( $params['orders'] ?? null ) ? $params['orders'] : array();
		$done   = array();
		$failed = array();
		foreach ( $orders as $o ) {
			$order = wc_get_order( (int) ( $o['order_id'] ?? 0 ) );
			if ( ! $order ) {
				$failed[] = '#' . ( $o['number'] ?? '?' );
				continue;
			}
			$order->update_status( $to, __( '經 Moksa AI 批次確認執行。', 'mo-ectools' ) );
			$fresh = wc_get_order( $order->get_id() );
			if ( $fresh && $fresh->get_status() === $to ) {
				$done[] = '#' . $order->get_order_number();
			} else {
				$failed[] = '#' . $order->get_order_number();
			}
		}

		$label = $statuses[ 'wc-' . $to ];
		$msg   = sprintf(
			/* translators: 1: success count, 2: total count, 3: status label, 4: order numbers */
			__( '✅ 已將 %1$d/%2$d 筆訂單改為「%3$s」:%4$s。', 'mo-ectools' ),
			count( $done ),
			count( $done ) + count( $failed ),
			$label,
			implode( ' ', $done )
		);
		if ( ! empty( $failed ) ) {
			$msg .= ' ' . sprintf(
				/* translators: %s: failed order numbers */
				__( '⚠️ 失敗:%s,請至訂單頁確認。', 'mo-ectools' ),
				implode( ' ', $failed )
			);
		}
		return $msg;
	}

	/**
	 * 把 orders 參數正規化成訂單參照字串陣列(去重、去空、上限保護)。
	 *
	 * @param mixed $raw array 或逗號 / 空白分隔字串。
	 * @return string[]
	 */
	private static function normalize_refs( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,，、]+/u', $raw ) ?: array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$out[ $item ] = $item;
			}
		}
		return array_slice( array_values( $out ), 0, self::MAX );
	}
}
