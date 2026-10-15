<?php
namespace Moksafowo\Modules\Payuni\Admin;

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\Payuni\PayuniPayment;
use Moksafowo\Modules\Payuni\Utils\OrderMeta;
use Moksafowo\Modules\Payuni\Utils\AuthType;
use Moksafowo\Modules\Payuni\Utils\BankType;
use Moksafowo\Modules\Payuni\Utils\TradeStatus;
use Moksafowo\Modules\Payuni\Utils\SingletonTrait;
use Moksafowo\Modules\Shared\Admin\OrderInfoLayout;

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
			'title' => __( 'Payment information', 'moksa-for-woocommerce' ),
			// 退款紀錄跟其他金流卡片一樣接在後面。
			'html'  => self::card_html( $order ) . \Moksafowo\Modules\Shared\Admin\CardRenderers::render_refund_block( $order ),
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
		echo '<tr><td><strong>' . esc_html__( 'Transaction ID', 'moksa-for-woocommerce' ) . '</strong></td><td style="word-break:break-all;">' . esc_html( $order->get_meta( $payuni_order_no_key ) ) . '</td></tr>';
		foreach ( $gateway::get_order_metas() as $key => $value ) {
			// for backward compatibility.
			$key = PayuniPayment::get_order_meta_key( $order, $key );
			if ( '' === (string) $order->get_meta( $key ) ) {
				continue;
			}
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

			$invoice_rows = [
				__( 'Invoice number', 'moksa-for-woocommerce' ) => $order->get_meta( OrderMeta::EINVOICE_NO ),
				__( 'Invoice amount', 'moksa-for-woocommerce' ) => $order->get_meta( OrderMeta::EINVOICE_AMT ),
				__( 'Issued at', 'moksa-for-woocommerce' ) => $order->get_meta( OrderMeta::EINVOICE_TIME ),
			];
			foreach ( $invoice_rows as $label => $invoice_value ) {
				if ( '' === (string) $invoice_value ) {
					continue;
				}
				echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( (string) $invoice_value ) . '</td></tr>';
			}

			// 只印有值的列，顯示中文說明；對不到的代碼才原樣顯示。
			$invoice_labels = [
				[
					__( 'Invoice type', 'moksa-for-woocommerce' ),
					(string) $order->get_meta( OrderMeta::EINVOICE_TYPE ),
					[
						'C0401' => __( 'Issue', 'moksa-for-woocommerce' ),
						'C0501' => __( 'Void', 'moksa-for-woocommerce' ),
					],
				],
				[
					__( 'Carrier details', 'moksa-for-woocommerce' ),
					(string) $order->get_meta( OrderMeta::EINVOICE_INFO ),
					[
						'3J0002'  => __( 'Mobile barcode', 'moksa-for-woocommerce' ),
						'CQ0001'  => __( 'Citizen Digital Certificate', 'moksa-for-woocommerce' ),
						'amego'   => __( 'Amego member', 'moksa-for-woocommerce' ),
						'Donate'  => __( 'Donation', 'moksa-for-woocommerce' ),
						'Company' => __( 'Company', 'moksa-for-woocommerce' ),
					],
				],
				[
					__( 'Issuing status', 'moksa-for-woocommerce' ),
					(string) $order->get_meta( OrderMeta::EINVOICE_STATUS ),
					[
						'1' => __( 'Issued', 'moksa-for-woocommerce' ),
						'2' => __( 'Issuing failed', 'moksa-for-woocommerce' ),
						'5' => __( 'Voided', 'moksa-for-woocommerce' ),
					],
				],
			];
			foreach ( $invoice_labels as [ $label, $code, $map ] ) {
				if ( '' === $code ) {
					continue;
				}
				echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( $map[ $code ] ?? $code ) . '</td></tr>';
			}
		}// end einvoice enabled

		echo '<tr id="moksafowo-payuni-action"><td colspan="2"><button id="moksafowo-payuni-query-btn" class="button" data-id="' . esc_attr( (string) $order->get_id() ) . '">' . esc_html__( 'Look up', 'moksa-for-woocommerce' ) . '</button></td></tr>';
		echo '</table>';

		return (string) ob_get_clean();
	}
}
