<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Paynow\Gateways;

use MoksaWeb\Mowc\Modules\Paynow\Api\Helper;
use MoksaWeb\Mowc\Modules\Paynow\Api\PaymentRequest;
use MoksaWeb\Mowc\Modules\Shared\Gateways\AbstractMowcGateway;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

abstract class AbstractPaynowGateway extends AbstractMowcGateway {

	abstract protected function pay_type(): string;

	protected function code_type(): string {
		return '';
	}

	protected function extra_params( \WC_Order $order ): array {
		return [];
	}

	protected function min_amount(): int {
		return 30;
	}

	protected function helper_has_credentials(): bool {
		return Helper::has_credentials();
	}

	protected function register_receipt_action(): void {
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'render_paynow_form' ] );
	}


	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( esc_html__( '找不到訂單', 'mo-ectools' ) );
		}

		$order_no = Helper::generate_order_no( (int) $order_id );
		$order->update_meta_data( Keys::PAYNOW_ORDER_NO, $order_no );
		$order->update_meta_data( Keys::PAYNOW_PAY_TYPE, $this->pay_type() );
		$order->save();

		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		];
	}

	public function render_paynow_form( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order_no = (string) $order->get_meta( Keys::PAYNOW_ORDER_NO );
		if ( '' === $order_no ) {
			$order_no = Helper::generate_order_no( $order_id );
			$order->update_meta_data( Keys::PAYNOW_ORDER_NO, $order_no );
			$order->save();
		}

		$args = [
			'order_no'       => $order_no,
			'total_price'    => (int) ceil( (float) $order->get_total() ),
			'pay_type'       => $this->pay_type(),
			'code_type'      => $this->code_type(),
			'receiver_name'  => $order->get_formatted_billing_full_name(),
			'receiver_id'    => $this->resolve_receiver_id( $order ),
			'receiver_tel'   => (string) $order->get_billing_phone(),
			'receiver_email' => (string) $order->get_billing_email(),
			'order_info'     => $this->build_order_info( $order ),
			'extra'          => $this->extra_params( $order ),
		];

		$params = PaymentRequest::build_params( $args );

		Helper::log(
			'form redirect',
			[
				'order_id' => $order_id,
				'order_no' => $order_no,
				'gateway'  => $this->id,
				'pay_type' => $this->pay_type(),
				'amt'      => $args['total_price'],
			]
		);

		// render_form 內部已 esc_*，輸出端仍過 wp_kses 白名單；auto-submit 走官方 inline script API。
		echo wp_kses(
			PaymentRequest::render_form( $params ),
			[
				'form'   => [
					'method'         => true,
					'id'             => true,
					'action'         => true,
					'accept-charset' => true,
				],
				'input'  => [
					'type'  => true,
					'name'  => true,
					'value' => true,
					'id'    => true,
				],
				'button' => [
					'type'  => true,
					'id'    => true,
					'class' => true,
				],
			]
		);
		wp_print_inline_script_tag( 'document.getElementById("moksafowo-paynow-form").submit();' );
	}

	private function resolve_receiver_id( \WC_Order $order ): string {
		$email = (string) $order->get_billing_email();
		if ( '' !== $email ) {
			return $email;
		}
		$phone = preg_replace( '/[^0-9]/', '', (string) $order->get_billing_phone() ) ?? '';
		return '' !== $phone ? $phone : (string) $order->get_id();
	}

	protected function build_order_info( \WC_Order $order ): string {
		$admin = trim( (string) get_option( 'moksafowo_paynow_order_info', '' ) );
		if ( '' !== $admin ) {
			return $admin;
		}
		$names = [];
		foreach ( $order->get_items() as $item ) {
			$names[] = (string) $item->get_name();
		}
		$joined = trim( implode( ', ', $names ) );
		if ( '' !== $joined ) {
			return $joined;
		}
		/* translators: %s: site name */
		return sprintf( __( '%s 訂單', 'mo-ectools' ), get_bloginfo( 'name' ) ) . ' #' . $order->get_order_number();
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$order->add_order_note(
				sprintf(
				/* translators: 1: amount, 2: reason */
					__( 'PayNow 退款請至 PayNow 商家後台手動操作（金額 NT$%1$s）— %2$s', 'mo-ectools' ),
					(int) ceil( (float) $amount ),
					'' !== (string) $reason ? $reason : __( '無原因', 'mo-ectools' )
				)
			);
			$order->save();
		}
		return new \WP_Error(
			'moksafowo_paynow_manual_refund',
			__( 'PayNow 退款請至 PayNow 商家後台手動操作（避免自動退款觸發停權）。', 'mo-ectools' )
		);
	}
}
