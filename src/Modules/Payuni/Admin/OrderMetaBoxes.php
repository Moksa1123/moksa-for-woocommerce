<?php
namespace MoksaWeb\Mowc\Modules\Payuni\Admin;

defined( 'ABSPATH' ) || exit;

use MoksaWeb\Mowc\Modules\Payuni\PayuniPayment;
use MoksaWeb\Mowc\Modules\Payuni\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\Payuni\Utils\AuthType;
use MoksaWeb\Mowc\Modules\Payuni\Utils\BankType;
use MoksaWeb\Mowc\Modules\Payuni\Utils\TradeStatus;
use MoksaWeb\Mowc\Modules\Payuni\Utils\SingletonTrait;
use MoksaWeb\Mowc\Modules\Shared\Admin\OrderInfoLayout;

class OrderMetaBoxes {

	use SingletonTrait;

	public static function init() {
		self::get_instance();

		// PAYUNi 交易資訊整合進統一的「金流 / 物流 / 電子發票」metabox（slot=payment），不再獨立 postbox。
		OrderInfoLayout::boot();
		add_filter( 'moksafowo_order_info_cards', array( __CLASS__, 'add_card' ), 10, 2 );
	}

	public static function add_card( array $cards, \WC_Order $order ): array {
		if ( ! array_key_exists( $order->get_payment_method(), PayuniPayment::get_allowed_payments( $order ) ) ) {
			return $cards;
		}

		$cards[] = [
			'slot'  => 'payment',
			'title' => __( '金流資訊（PAYUNi）', 'mo-ectools' ),
			'html'  => self::card_html( $order ),
		];
		return $cards;
	}

	private static function card_html( \WC_Order $order ): string {
		$payment_method   = $order->get_payment_method();
		$allowed_payments = PayuniPayment::get_allowed_payments( $order );
		$gateway          = $allowed_payments[ $payment_method ];

		ob_start();
		echo '<table style="width:100%;font-size:12px;table-layout:fixed;">';

		$payuni_order_no_key = PayuniPayment::get_order_meta_key( $order, OrderMeta::PAYUNI_ORDER_NO );
		echo '<tr><td><strong>' . esc_html__( '交易序號', 'mo-ectools' ) . '</strong></td><td style="word-break:break-all;">' . esc_html( $order->get_meta( $payuni_order_no_key ) ) . '</td></tr>';
		foreach ( $gateway::get_order_metas() as $key => $value ) {
			// for backward compatibility.
			$key = PayuniPayment::get_order_meta_key( $order, $key );
			if ( $key === OrderMeta::CREDIT_AUTH_TYPE ) {
				echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td><td>' . esc_html( AuthType::get_type( $order->get_meta( $key ) ) ) . ' (' . esc_html( $order->get_meta( $key ) ) . ')</td></tr>';
			} elseif ( $key === OrderMeta::AMT_BANK_TYPE ) {
				echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td><td>' . esc_html( $order->get_meta( $key ) ) . ' (' . esc_html( BankType::get_name( $order->get_meta( $key ) ) ) . ')</td></tr>';
			} elseif ( $key === OrderMeta::TRADE_STATUS ) {
				$trade_status = $order->get_meta( $key );
				if ( isset( $trade_status ) ) {
					echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td><td><span class="moksafowo-payuni-trade-status-' . esc_attr( $trade_status ) . '">' . esc_html( TradeStatus::get_name( $trade_status, $payment_method ) ) . '</span></td></tr>';
				} else {
					echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td><td></td></tr>';
				}
			} elseif ( $key === OrderMeta::MESSAGE ) {
				echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td><td>' . esc_html( $order->get_meta( $key ) ) . ' (' . esc_html( $order->get_meta( OrderMeta::STATUS ) ) . ')</td></tr>';
			} else {
				echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td><td>' . esc_html( $order->get_meta( $key ) ) . '</td></tr>';
			}
		}

		if ( PayuniPayment::$einvoice_enabled ) {

			echo '<tr><td><strong>' . esc_html__( '發票號碼', 'mo-ectools' ) . '</strong></td><td>' . esc_html( $order->get_meta( OrderMeta::EINVOICE_NO ) ) . '</td></tr>';
			echo '<tr><td><strong>' . esc_html__( '發票金額', 'mo-ectools' ) . '</strong></td><td>' . esc_html( $order->get_meta( OrderMeta::EINVOICE_AMT ) ) . '</td></tr>';
			echo '<tr><td><strong>' . esc_html__( '開立時間', 'mo-ectools' ) . '</strong></td><td>' . esc_html( $order->get_meta( OrderMeta::EINVOICE_TIME ) ) . '</td></tr>';

			$einvoice_type = $order->get_meta( OrderMeta::EINVOICE_TYPE );
			if ( $einvoice_type === 'C0401' ) {
				$einvoice_type_desc = __( '開立', 'mo-ectools' );
			} elseif ( $einvoice_type === 'C0501' ) {
				$einvoice_type_desc = __( '作廢', 'mo-ectools' );
			} else {
				$einvoice_type_desc = __( '未知類型', 'mo-ectools' );
			}
			echo '<tr><td><strong>' . esc_html__( '發票類型', 'mo-ectools' ) . '</strong></td><td>' . esc_html( $einvoice_type . ' (' . $einvoice_type_desc . ')' ) . '</td></tr>';

			$einvoice_info = $order->get_meta( OrderMeta::EINVOICE_INFO );
			if ( $einvoice_info === '3J0002' ) {
				$einvoice_info_desc = __( '手機條碼', 'mo-ectools' );
			} elseif ( $einvoice_info === 'CQ0001' ) {
				$einvoice_info_desc = __( '自然人憑證', 'mo-ectools' );
			} elseif ( $einvoice_info === 'amego' ) {
				$einvoice_info_desc = __( 'Amego 會員', 'mo-ectools' );
			} elseif ( $einvoice_info === 'Donate' ) {
				$einvoice_info_desc = __( '捐贈', 'mo-ectools' );
			} elseif ( $einvoice_info === 'Company' ) {
				$einvoice_info_desc = __( '公司', 'mo-ectools' );
			} else {
				$einvoice_info_desc = __( '未知載具', 'mo-ectools' );
			}
			echo '<tr><td><strong>' . esc_html__( '載具資訊', 'mo-ectools' ) . '</strong></td><td>' . esc_html( $einvoice_info . ' (' . $einvoice_info_desc . ')' ) . '</td></tr>';

			$einvoice_status = $order->get_meta( OrderMeta::EINVOICE_STATUS );
			if ( $einvoice_status === '1' ) {
				$einvoice_status_desc = __( '已開立', 'mo-ectools' );
			} elseif ( $einvoice_status === '2' ) {
				$einvoice_status_desc = __( '開立失敗', 'mo-ectools' );
			} elseif ( $einvoice_status === '5' ) {
				$einvoice_status_desc = __( '已作廢', 'mo-ectools' );
			} else {
				$einvoice_status_desc = __( '未知狀態', 'mo-ectools' );
			}
			echo '<tr><td><strong>' . esc_html__( '開立狀態', 'mo-ectools' ) . '</strong></td><td>' . esc_html( $einvoice_status . ' (' . $einvoice_status_desc . ')' ) . '</td></tr>';
		}// end einvoice enabled

		echo '<tr id="moksafowo-payuni-action"><td colspan="2"><button id="moksafowo-payuni-query-btn" class="button" data-id="' . esc_attr( (string) $order->get_id() ) . '">' . esc_html__( '查詢', 'mo-ectools' ) . '</button></td></tr>';
		echo '</table>';

		return (string) ob_get_clean();
	}
}
