<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

use Moksafowo\Modules\Ecpay\Api\Helper;
use Moksafowo\Modules\Shared\Gateways\AbstractMowcGateway;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

abstract class AbstractEcpayGateway extends AbstractMowcGateway {

	abstract protected function choose_payment(): string;

	protected function extra_aio_params( \WC_Order $order ): array {
		return [];
	}

	protected function gateway_supports(): array {
		return $this->supports_credit_action() ? [ 'products', 'refunds' ] : [ 'products' ];
	}

	protected function register_receipt_action(): void {
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'render_aio_form' ] );
	}

	protected function supports_credit_action(): bool {
		return false;
	}

	protected static function payment_type_supports_refund( string $payment_type ): bool {
		if ( '' === $payment_type ) {
			return true; // unknown — 讓後續 API 自己回 error
		}
		$prefix = strtolower( strtok( $payment_type, '_' ) );
		return in_array( $prefix, [ 'credit', 'twqr', 'bnpl', 'applepay' ], true );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'moksafowo_ecpay_refund_invalid_order', __( '找不到訂單。', 'moksa-for-woocommerce' ) );
		}

		if ( ! $this->supports_credit_action() ) {
			return new \WP_Error(
				'moksafowo_ecpay_refund_unsupported',
				__( '此付款方式不支援自動退款，請至綠界後台手動操作。', 'moksa-for-woocommerce' )
			);
		}

		// Unified（single mode）的實際付款方式由顧客在綠界 AIO 選擇，IPN 回填到 PAYMENT_TYPE meta。
		// 線下付款（ATM/CVS/BARCODE/WebATM）沒有 DoAction，提早擋回避免發送無效請求。
		$payment_type = (string) $order->get_meta( Keys::ECPAY_PAYMENT_TYPE );
		if ( '' !== $payment_type && ! self::payment_type_supports_refund( $payment_type ) ) {
			return new \WP_Error(
				'moksafowo_ecpay_refund_offline_payment',
				sprintf(
					/* translators: %s: ECPay payment type */
					__( '此訂單為線下付款（%s），綠界沒有自動退款 API，請至綠界後台手動退款。', 'moksa-for-woocommerce' ),
					$payment_type
				)
			);
		}

		$amount_int = (int) round( (float) $amount );
		if ( $amount_int <= 0 ) {
			return new \WP_Error( 'moksafowo_ecpay_refund_amount', __( '退款金額需大於 0。', 'moksa-for-woocommerce' ) );
		}

		$result = Helper::credit_action( $order, 'R', $amount_int );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
				/* translators: %s: error message */
					__( '綠界退款失敗：%s', 'moksa-for-woocommerce' ),
					$result->get_error_message()
				)
			);
			return $result;
		}

		$rtn_code = (int) ( $result['RtnCode'] ?? 0 );
		$rtn_msg  = (string) ( $result['RtnMsg'] ?? '' );

		// ECPay DoAction RtnCode = 1 為成功
		if ( 1 !== $rtn_code ) {
			$msg = sprintf(
				/* translators: 1: amount, 2: ECPay message, 3: code */
				__( '綠界退款失敗（金額 NT$%1$d）：%2$s（代碼 %3$d）', 'moksa-for-woocommerce' ),
				$amount_int,
				$rtn_msg,
				$rtn_code
			);
			$order->add_order_note( $msg );
			return new \WP_Error( 'moksafowo_ecpay_refund_failed', $msg );
		}

		$order->add_order_note(
			sprintf(
			/* translators: 1: amount, 2: reason */
				__( '綠界退款成功（金額 NT$%1$d）。原因：%2$s', 'moksa-for-woocommerce' ),
				$amount_int,
				'' === $reason ? __( '（未填寫）', 'moksa-for-woocommerce' ) : $reason
			)
		);
		return true;
	}

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new \Exception( esc_html__( 'Invalid order', 'moksa-for-woocommerce' ) );
		}

		$merchant_trade_no = Helper::generate_merchant_trade_no( (int) $order_id );
		$order->update_meta_data( Keys::ECPAY_MERCHANT_TRADE_NO, $merchant_trade_no );
		$order->save();

		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		];
	}

	public function render_aio_form( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$merchant_trade_no = (string) $order->get_meta( Keys::ECPAY_MERCHANT_TRADE_NO );
		if ( '' === $merchant_trade_no ) {
			$merchant_trade_no = Helper::generate_merchant_trade_no( $order_id );
			$order->update_meta_data( Keys::ECPAY_MERCHANT_TRADE_NO, $merchant_trade_no );
			$order->save();
		}

		$total     = (int) round( (float) $order->get_total() );
		$item_name = trim( (string) get_option( 'moksafowo_ecpay_payment_item_name', '' ) );
		if ( '' === $item_name ) {
			$first = null;
			foreach ( $order->get_items() as $i ) {
				$first = $i;
				break;
			}
			if ( $first ) {
				$item_name = (string) $first->get_name();
			}
		}
		if ( '' === $item_name ) {
			$item_name = sprintf(
				/* translators: %s: site name */
				__( '%s 訂單', 'moksa-for-woocommerce' ),
				get_bloginfo( 'name' )
			) . ' #' . $order->get_order_number();
		}

		$params = [
			'MerchantID'        => Helper::merchant_id(),
			'MerchantTradeNo'   => $merchant_trade_no,
			'MerchantTradeDate' => current_time( 'Y/m/d H:i:s' ),
			'PaymentType'       => 'aio',
			'TotalAmount'       => $total,
			'TradeDesc'         => $item_name,
			'ItemName'          => $item_name,
			'ReturnURL'         => home_url( '/wc-api/moksafowo_ecpay_payment' ),
			'ChoosePayment'     => $this->choose_payment(),
			'EncryptType'       => 1,
			'ClientBackURL'     => $order->get_checkout_order_received_url(),
		];

		$params                  = array_merge( $params, $this->extra_aio_params( $order ) );
		$params['CheckMacValue'] = Helper::generate_check_mac_value( $params );

		Helper::log(
			'AIO redirect',
			[
				'order_id'          => $order_id,
				'merchant_trade_no' => $merchant_trade_no,
				'choose_payment'    => $this->choose_payment(),
			]
		);

		echo '<form id="moksafowo-ecpay-aio" method="post" action="' . esc_url( Helper::aio_endpoint() ) . '">';
		foreach ( $params as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
		echo '<noscript><button type="submit">' . esc_html__( '前往綠界付款', 'moksa-for-woocommerce' ) . '</button></noscript>';
		echo '</form>';
		wp_print_inline_script_tag( 'document.getElementById("moksafowo-ecpay-aio").submit();' );
	}
}
