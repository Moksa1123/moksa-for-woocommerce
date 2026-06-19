<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

use MoksaWeb\Mowc\Modules\Shared\Frontend\PaymentInfoBox;

defined( 'ABSPATH' ) || exit;

/**
 * 重寄「付款資訊信」(ATM 虛擬帳號 / 超商繳費代碼等)給顧客。
 *
 * 走人工確認關卡:寄信給顧客屬可逆但會打擾的動作。底層 fire
 * do_action('moksafowo_payment_info_email', $id)(與首次取號通知同一條路),
 * 直接觸發繞過 PAYMENT_INFO_EMAIL_SENT guard(本來就是要重寄)。
 * 只有「有付款資訊可寄」(ATM/CVS 等,信用卡訂單沒有)的訂單才允許。
 */
final class ResendPaymentEmail {

	const CAP = 'manage_woocommerce';

	private static function order_from( $args ): ?\WC_Order {
		$ref   = is_array( $args ) && isset( $args['order'] ) ? (string) $args['order'] : '';
		$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
		$order = $id ? wc_get_order( $id ) : false;
		return ( $order && 'shop_order' === $order->get_type() ) ? $order : null;
	}

	/**
	 * @param mixed $args { order: string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$order = self::order_from( $args );
		if ( ! $order ) {
			return new \WP_Error( 'mo_ai_no_order', __( '找不到訂單。', 'mo-ectools' ) );
		}
		if ( empty( PaymentInfoBox::rows( $order ) ) ) {
			return new \WP_Error( 'mo_ai_no_payinfo', __( '此訂單沒有可重寄的付款資訊(例如信用卡訂單沒有 ATM / 超商繳費資訊)。', 'mo-ectools' ) );
		}
		$email = (string) $order->get_billing_email();
		if ( '' === $email ) {
			return new \WP_Error( 'mo_ai_no_email', __( '此訂單沒有顧客 Email。', 'mo-ectools' ) );
		}

		return array(
			'order_id' => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'email'    => $email,
			'summary'  => sprintf(
				/* translators: 1: order number, 2: customer email */
				__( '重寄付款資訊信(ATM / 超商繳費資訊)給訂單 #%1$s 的顧客 %2$s。', 'mo-ectools' ),
				$order->get_order_number(),
				$email
			),
		);
	}

	/**
	 * @param array<string,mixed> $params prepare() 的回傳。
	 * @return string|\WP_Error
	 */
	public static function apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$order = wc_get_order( (int) ( $params['order_id'] ?? 0 ) );
		if ( ! $order ) {
			return new \WP_Error( 'mo_ai_no_order', __( '找不到訂單。', 'mo-ectools' ) );
		}
		if ( empty( PaymentInfoBox::rows( $order ) ) ) {
			return new \WP_Error( 'mo_ai_no_payinfo', __( '此訂單沒有可重寄的付款資訊。', 'mo-ectools' ) );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- moksafowo 為本外掛前綴。
		do_action( 'moksafowo_payment_info_email', $order->get_id() );
		$order->add_order_note( __( '經 Moksa AI 確認重寄付款資訊信。', 'mo-ectools' ) );
		$order->save();

		return sprintf(
			/* translators: 1: order number, 2: customer email */
			__( '✅ 已重寄訂單 #%1$s 的付款資訊信給 %2$s。', 'mo-ectools' ),
			$order->get_order_number(),
			(string) ( $params['email'] ?? $order->get_billing_email() )
		);
	}
}
