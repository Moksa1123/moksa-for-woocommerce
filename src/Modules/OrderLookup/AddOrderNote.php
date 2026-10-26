<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * Moksa AI 對話裡的「加訂單備註」：先預覽、使用者按確認才寫入。
 *
 * 訂單內容（顧客姓名、地址、客服留言）會進到模型上下文，被誘導的模型可能替別張訂單寫備註；
 * 寫入前讓使用者看到訂單號與完整內容。MCP 由商家自己串接、已驗證身分，仍直接執行。
 */
final class AddOrderNote {

	const CAP = 'edit_shop_orders';

	/**
	 * @param mixed $args { order: string, note: string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) );
		}
		$ref   = is_array( $args ) && isset( $args['order'] ) ? (string) $args['order'] : '';
		$note  = is_array( $args ) && isset( $args['note'] ) ? trim( sanitize_textarea_field( (string) $args['note'] ) ) : '';
		$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
			return new \WP_Error( 'moksafowo_ai_no_order', __( 'The order could not be found.', 'moksa-for-woocommerce' ) );
		}
		if ( '' === $note ) {
			return new \WP_Error( 'moksafowo_ai_empty_note', __( 'The note cannot be empty.', 'moksa-for-woocommerce' ) );
		}
		return array(
			'order_id' => $order->get_id(),
			'note'     => $note,
			'summary'  => sprintf(
				/* translators: 1: order number, 2: note text */
				__( 'Add this internal note to order #%1$s: “%2$s”', 'moksa-for-woocommerce' ),
				$order->get_order_number(),
				$note
			),
		);
	}

	/**
	 * @param array<string,mixed> $params prepare() 的結果。
	 * @return string|\WP_Error
	 */
	public static function apply( array $params ) {
		$result = Ability::execute_add_note(
			array(
				'order' => (string) ( $params['order_id'] ?? '' ),
				'note'  => (string) ( $params['note'] ?? '' ),
			)
		);
		if ( empty( $result['ok'] ) ) {
			return new \WP_Error( 'moksafowo_ai_note_failed', (string) ( $result['message'] ?? '' ) );
		}
		return (string) $result['message'];
	}
}
