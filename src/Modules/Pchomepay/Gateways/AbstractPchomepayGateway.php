<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Pchomepay\Gateways;

use MoksaWeb\Mowc\Modules\Pchomepay\Api\Helper;
use MoksaWeb\Mowc\Modules\Pchomepay\Api\PaymentRequest;
use MoksaWeb\Mowc\Modules\Shared\Gateways\AbstractMowcGateway;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

abstract class AbstractPchomepayGateway extends AbstractMowcGateway {

	abstract protected function pay_types(): array;

	protected function extra_params( \WC_Order $order ): array {
		return [];
	}

	protected function gateway_supports(): array {
		return [ 'products', 'refunds' ];
	}

	protected function helper_has_credentials(): bool {
		return Helper::has_credentials();
	}

	
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( esc_html__( '找不到訂單', 'mo-ectools' ) );
		}

		// 訂單若已送過（曾失敗重試），order_id 加 T{time} 後綴避免重複。
		$retry           = '' !== (string) $order->get_meta( Keys::PCHOMEPAY_ORDER_ID );
		$pchome_order_id = Helper::generate_order_id( (int) $order_id, $retry );

		$args = array_merge( [
			'order_id'        => $pchome_order_id,
			'pay_type'        => $this->pay_types(),
			'amount'          => (int) ceil( (float) $order->get_total() ),
			'items'           => $this->build_items( $order ),
			'return_url'      => $order->get_checkout_order_received_url(),
			'fail_return_url' => $order->get_checkout_payment_url( false ),
			'notify_url'      => home_url( '/wc-api/moksafowo_pchomepay_payment' ),
			'buyer_name'      => $order->get_formatted_billing_full_name(),
			'buyer_email'     => $order->get_billing_email(),
			'buyer_mobile'    => $order->get_billing_phone(),
		], $this->extra_params( $order ) );

		$resp = PaymentRequest::create( $args );

		Helper::log( 'payment created', [
			'order_id'        => $order_id,
			'pchome_order_id' => $pchome_order_id,
			'gateway'         => $this->id,
			'ok'              => $resp['ok'],
			'code'            => $resp['code'],
		] );

		if ( ! $resp['ok'] || '' === $resp['payment_url'] ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: error message */
					__( '無法建立支付連付款：%s', 'mo-ectools' ),
					$resp['message']
				),
				'error'
			);
			return [ 'result' => 'failure', 'redirect' => '' ];
		}

		$order->update_meta_data( Keys::PCHOMEPAY_ORDER_ID, $pchome_order_id );
		$order->update_meta_data( Keys::PCHOMEPAY_PAYMENT_URL, $resp['payment_url'] );
		$order->update_meta_data( Keys::PCHOMEPAY_PAY_TYPE, implode( ',', $this->pay_types() ) );
		$order->update_status( 'pending', __( '等待顧客於支付連完成付款。', 'mo-ectools' ) );
		$order->save();

		return [
			'result'   => 'success',
			'redirect' => $resp['payment_url'],
		];
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'moksafowo_pchomepay_invalid_order', __( '訂單不存在。', 'mo-ectools' ) );
		}
		$pchome_order_id = (string) $order->get_meta( Keys::PCHOMEPAY_ORDER_ID );
		if ( '' === $pchome_order_id ) {
			return new \WP_Error( 'moksafowo_pchomepay_missing_order_id', __( '訂單缺少支付連交易編號。', 'mo-ectools' ) );
		}

		$amt = (int) ceil( (float) $amount );
		if ( $amt <= 0 ) {
			return new \WP_Error( 'moksafowo_pchomepay_invalid_amount', __( '退款金額必須大於 0。', 'mo-ectools' ) );
		}

		// 全額退限制：超商取貨 / 代碼繳費 / 信用卡分期。
		$pay_type    = (string) $order->get_meta( Keys::PCHOMEPAY_PAY_TYPE );
		$full_only   = [ 'IPL7', 'IPLFM', 'IPLHL', 'BCODE' ];
		$order_total = (int) ceil( (float) $order->get_total() );
		foreach ( $full_only as $ft ) {
			if ( str_contains( $pay_type, $ft ) && $amt < $order_total ) {
				return new \WP_Error(
					'moksafowo_pchomepay_full_refund_only',
					sprintf(
						/* translators: %s: pay type */
						__( '此付款方式（%s）僅能全額退款。', 'mo-ectools' ),
						$pay_type
					)
				);
			}
		}

		$refund_id = 'R' . $order_id . substr( (string) time(), -6 );
		$result    = PaymentRequest::refund( $pchome_order_id, $refund_id, $amt );
		if ( ! $result['ok'] ) {
			return new \WP_Error(
				'moksafowo_pchomepay_refund_fail',
				sprintf(
					/* translators: %s: error message */
					__( '支付連退款失敗：%s', 'mo-ectools' ),
					$result['message']
				)
			);
		}

		$order->add_order_note( sprintf(
			/* translators: 1: amount, 2: refund id, 3: reason */
			__( '支付連退款已送出（NT$%1$s，退款編號 %2$s）— %3$s', 'mo-ectools' ),
			$amt,
			$refund_id,
			$reason ?: __( '無原因', 'mo-ectools' )
		) );
		$order->save();
		return true;
	}

	protected function build_items( \WC_Order $order ): array {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$url     = $product ? get_permalink( $product->get_id() ) : home_url();
			$items[] = [
				'name' => mb_substr( (string) $item->get_name(), 0, 60 ),
				'url'  => is_string( $url ) ? $url : home_url(),
			];
		}
		if ( empty( $items ) ) {
			$items[] = [
				/* translators: %s: site name */
				'name' => sprintf( __( '%s 訂單', 'mo-ectools' ), get_bloginfo( 'name' ) ),
				'url'  => home_url(),
			];
		}
		return $items;
	}
}
