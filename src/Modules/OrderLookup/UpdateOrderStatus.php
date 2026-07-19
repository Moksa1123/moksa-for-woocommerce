<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 破壞性操作:更改訂單狀態。
 *
 * prepare() 只「驗證 + 描述」(不變更),給 AI 提議 / 確認關卡用;
 * apply() 才真正變更(由使用者按「確認執行」後經 ai-confirm REST 呼叫)。
 * 兩者都要 manage_woocommerce。
 */
final class UpdateOrderStatus {

	const CAP = 'manage_woocommerce';

	/**
	 * 驗證並描述要做的變更(不執行)。
	 *
	 * @param mixed $args { order: string|int, status: string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'moksa-for-woocommerce' ) );
		}

		$ref    = is_array( $args ) && isset( $args['order'] ) ? (string) $args['order'] : '';
		$status = is_array( $args ) && isset( $args['status'] ) ? str_replace( 'wc-', '', sanitize_key( (string) $args['status'] ) ) : '';

		$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order ) {
			/* translators: %s: order reference the user gave */
			return new \WP_Error( 'moksafowo_ai_no_order', sprintf( __( '找不到訂單:%s。', 'moksa-for-woocommerce' ), $ref ) );
		}

		$statuses = wc_get_order_statuses();
		$to_key   = 'wc-' . $status;
		if ( ! isset( $statuses[ $to_key ] ) ) {
			/* translators: %s: status slug the user gave */
			return new \WP_Error( 'moksafowo_ai_bad_status', sprintf( __( '無效的訂單狀態:%s。', 'moksa-for-woocommerce' ), $status ) );
		}

		$from_label = wc_get_order_status_name( $order->get_status() );
		$to_label   = $statuses[ $to_key ];

		return array(
			'order_id'   => $order->get_id(),
			'number'     => (string) $order->get_order_number(),
			'to_slug'    => $status,
			'to_label'   => $to_label,
			'from_label' => $from_label,
			/* translators: 1: order number, 2: current status, 3: target status */
			'summary'    => sprintf( __( '將訂單 #%1$s 的狀態從「%2$s」改為「%3$s」。', 'moksa-for-woocommerce' ), $order->get_order_number(), $from_label, $to_label ),
		);
	}

	/**
	 * 真正執行變更(使用者確認後)。
	 *
	 * @param array<string,mixed> $params prepare() 的回傳值。
	 * @return string|\WP_Error 成功訊息或錯誤。
	 */
	public static function apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'moksa-for-woocommerce' ) );
		}

		$order = wc_get_order( (int) ( $params['order_id'] ?? 0 ) );
		if ( ! $order ) {
			return new \WP_Error( 'moksafowo_ai_no_order', __( '找不到訂單。', 'moksa-for-woocommerce' ) );
		}

		$to       = (string) ( $params['to_slug'] ?? '' );
		$statuses = wc_get_order_statuses();
		if ( ! isset( $statuses[ 'wc-' . $to ] ) ) {
			return new \WP_Error( 'moksafowo_ai_bad_status', __( '無效的訂單狀態。', 'moksa-for-woocommerce' ) );
		}

		$order->update_status( $to, __( '經 Moksa AI 確認執行。', 'moksa-for-woocommerce' ) );

		$fresh     = wc_get_order( $order->get_id() );
		$now       = $fresh ? $fresh->get_status() : '';
		$now_label = $statuses[ 'wc-' . $now ] ?? $now;

		if ( $now === $to ) {
			/* translators: 1: order number, 2: status label */
			return sprintf( __( '✅ 已將訂單 #%1$s 改為「%2$s」,並回查訂單核對 —— 目前狀態確實為「%2$s」。', 'moksa-for-woocommerce' ), $order->get_order_number(), $now_label );
		}

		/* translators: 1: order number, 2: actual status, 3: expected status */
		return sprintf( __( '⚠️ 變更已送出,但回查訂單 #%1$s 目前狀態為「%2$s」(預期「%3$s」),請至訂單頁再次確認。', 'moksa-for-woocommerce' ), $order->get_order_number(), $now_label, $statuses[ 'wc-' . $to ] );
	}
}
