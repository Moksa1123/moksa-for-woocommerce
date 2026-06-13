<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay\Admin;

use MoksaWeb\Mowc\Modules\Linepay\Constants;
use MoksaWeb\Mowc\Modules\Linepay\LinePay;
use MoksaWeb\Mowc\Modules\Shared\Admin\OrderInfoLayout;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox {

	public static function init(): void {
		// LINE Pay 交易資訊整合進統一的「金流 / 物流 / 電子發票」metabox（slot=payment），不再獨立 postbox。
		OrderInfoLayout::boot();
		add_filter( 'moksafowo_order_info_cards', array( __CLASS__, 'add_card' ), 10, 2 );
	}

	public static function add_card( array $cards, \WC_Order $order ): array {
		if ( ! array_key_exists( $order->get_payment_method(), LinePay::$allowed_payments ) ) {
			return $cards;
		}

		$cards[] = [
			'slot'  => 'payment',
			'title' => __( '金流資訊', 'mo-ectools' ),
			'html'  => self::card_html( $order ),
		];
		return $cards;
	}

	private static function card_html( \WC_Order $order ): string {
		$raw_status    = (string) $order->get_meta( '_moksafowo_linepay_payment_status' );
		$status_labels = array(
			Constants::PAYMENT_STATUS_RESERVED  => __( '已預授權（待請款）', 'mo-ectools' ),
			Constants::PAYMENT_STATUS_AUTHED    => __( '已授權（待請款）', 'mo-ectools' ),
			Constants::PAYMENT_STATUS_CONFIRMED => __( '已請款', 'mo-ectools' ),
			Constants::PAYMENT_STATUS_CANCELLED => __( '已取消', 'mo-ectools' ),
			Constants::PAYMENT_STATUS_REFUNDED  => __( '已退款', 'mo-ectools' ),
			Constants::PAYMENT_STATUS_FAILED    => __( '失敗', 'mo-ectools' ),
		);
		$status_label  = $status_labels[ $raw_status ] ?? $raw_status;

		ob_start();
		echo '<table style="width:100%;font-size:12px;table-layout:fixed;">';
		echo '<tr><th style="text-align:left;"><div id="order-id" data-order-id="' . esc_attr( (string) $order->get_id() ) . '">' . esc_html__( '交易編號', 'mo-ectools' ) . '</div></th><td style="word-break:break-all;">' . esc_html( (string) $order->get_meta( '_moksafowo_linepay_reserved_transaction_id' ) ) . '</td></tr>';
		echo '<tr><th style="text-align:left;"><div>' . esc_html__( '交易狀態', 'mo-ectools' ) . '</div></th><td>' . esc_html( $status_label ) . '</td></tr>';

		if ( Constants::PAYMENT_STATUS_AUTHED === $raw_status ) {
			echo '<tr id="moksafowo-linepay-action"><th style="text-align:left;">' . esc_html__( '付款動作', 'mo-ectools' ) . '</th><td><button class="button moksafowo-linepay-confirm-btn" data-id="' . esc_attr( (string) $order->get_id() ) . '">' . esc_html__( '確認請款', 'mo-ectools' ) . '</button></td></tr>';
		}

		echo '</table>';

		return (string) ob_get_clean();
	}
}
