<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

use MoksaWeb\Mowc\Modules\Shipping\Admin\BatchPrintAdminUI;

defined( 'ABSPATH' ) || exit;

/**
 * 列印物流單(單筆 / 批次)。走人工確認關卡：prepare 只描述，apply 才建立列印表單並
 * 回傳列印輸出頁 URL(前端開新分頁列印)。列印既有標籤本身無破壞性副作用，但牽涉
 * 與物流商互動，故比照確認關卡(使用者按「確認執行」即等於人工審核)。
 */
final class PrintShippingLabel {

	const CAP = 'edit_shop_orders';
	const MAX = 50;

	/**
	 * @param mixed $args { orders: array|string, paper?: string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要訂單編輯權限。', 'mo-ectools' ) );
		}

		$refs = self::normalize_refs( is_array( $args ) ? ( $args['orders'] ?? '' ) : '' );
		if ( empty( $refs ) ) {
			return new \WP_Error( 'mo_ai_no_orders', __( '沒有指定要列印的訂單。', 'mo-ectools' ) );
		}

		$ids     = array();
		$numbers = array();
		$invalid = array();
		foreach ( $refs as $ref ) {
			$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
			$order = $id ? wc_get_order( $id ) : false;
			if ( ! $order || 'shop_order' !== $order->get_type() ) {
				$invalid[] = $ref;
				continue;
			}
			$ids[ $order->get_id() ] = $order->get_id();
			$numbers[]               = '#' . $order->get_order_number();
		}

		if ( empty( $ids ) ) {
			return new \WP_Error( 'mo_ai_no_orders', __( '找不到任何有效的訂單。', 'mo-ectools' ) );
		}

		$paper   = ( is_array( $args ) && isset( $args['paper'] ) && in_array( (string) $args['paper'], array( '2', 'a6', 'A6' ), true ) ) ? '2' : '1';
		$summary = sprintf(
			/* translators: 1: order count, 2: order numbers, 3: paper size */
			__( '列印 %1$d 筆訂單的物流標籤(%2$s),紙張 %3$s。', 'mo-ectools' ),
			count( $ids ),
			implode( ' ', $numbers ),
			'2' === $paper ? 'A6' : 'A4'
		);
		if ( ! empty( $invalid ) ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: invalid refs */
				__( '(找不到並略過:%s)', 'mo-ectools' ),
				implode( '、', $invalid )
			);
		}

		return array(
			'order_ids' => array_values( $ids ),
			'paper'     => $paper,
			'summary'   => $summary,
		);
	}

	/**
	 * @param array<string,mixed> $params prepare() 的回傳。
	 * @return array{print_url:string, message:string}|\WP_Error
	 */
	public static function apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要訂單編輯權限。', 'mo-ectools' ) );
		}
		$ids   = is_array( $params['order_ids'] ?? null ) ? array_map( 'absint', $params['order_ids'] ) : array();
		$paper = (string) ( $params['paper'] ?? '1' );

		$result = BatchPrintAdminUI::build_print_url( $ids, $paper );
		if ( empty( $result['ok'] ) ) {
			return new \WP_Error( 'mo_ai_print_failed', (string) ( $result['message'] ?? __( '無法產生列印標籤。', 'mo-ectools' ) ) );
		}

		$message = sprintf(
			/* translators: %d: number of labels */
			__( '✅ 已準備 %d 份物流標籤,正在開啟列印頁。', 'mo-ectools' ),
			(int) $result['count']
		);
		if ( ! empty( $result['skipped'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: skipped count */
				__( '(略過 %d 筆無標籤)', 'mo-ectools' ),
				(int) $result['skipped']
			);
		}

		return array(
			'print_url' => (string) $result['url'],
			'message'   => $message,
		);
	}

	/**
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
