<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Smilepay\Gateways;

use Moksafowo\Modules\Shared\Gateways\AbstractMowcGateway;
use Moksafowo\Modules\Smilepay\Api\Helper;
use Moksafowo\Modules\Smilepay\Api\IpnHandler;
use Moksafowo\Modules\Smilepay\Api\Request;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

abstract class AbstractSmilepayGateway extends AbstractMowcGateway {

	abstract protected function pay_zg(): string;

	abstract protected function redirect_flow(): bool;

	protected function extra_params( \WC_Order $order ): array {
		return [];
	}

	protected function mtmk_extra_params( \WC_Order $order ): array {
		return [];
	}

	protected function helper_has_credentials(): bool {
		return Helper::has_credentials();
	}

	protected function build_form_fields(): array {
		$fields                         = parent::build_form_fields();
		$fields['order_successed_text'] = [
			'title'       => __( 'Order confirmation message', 'moksa-for-woocommerce' ),
			'type'        => 'textarea',
			'default'     => '',
			'description' => __( 'An optional extra message shown on the thank you page after the order is placed.', 'moksa-for-woocommerce' ),
			'desc_tip'    => true,
		];
		return $fields;
	}


	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( esc_html__( 'The order could not be found', 'moksa-for-woocommerce' ) );
		}

		$order->update_meta_data( Keys::SMILEPAY_PAY_ZG, $this->pay_zg() );
		$order->update_meta_data( Keys::SMILEPAY_PAY_GATEWAY, $this->id );
		$success_text = trim( (string) $this->get_option( 'order_successed_text', '' ) );
		if ( '' !== $success_text ) {
			$order->update_meta_data( Keys::SMILEPAY_PAY_SUCCESS_TEXT, $success_text );
		}

		return $this->redirect_flow()
			? $this->process_redirect_flow( $order )
			: $this->process_charge_flow( $order );
	}


	private function process_redirect_flow( \WC_Order $order ): array {
		$args = array_merge(
			[
				'Pay_zg'        => $this->pay_zg(),
				'Pur_name'      => $order->get_billing_last_name() . $order->get_billing_first_name(),
				'Tel_number'    => (string) $order->get_billing_phone(),
				'Mobile_number' => (string) $order->get_billing_phone(),
				'Address'       => $order->get_billing_address_1() . $order->get_billing_address_2(),
				'Email'         => (string) $order->get_billing_email(),
				'Data_id'       => (string) $order->get_id(),
				'od_sob'        => Helper::build_products_summary( $order, 49 ),
				'Amount'        => (string) (int) ceil( (float) $order->get_total() ),
				'Roturl'        => home_url( '/wc-api/moksafowo_smilepay_credit_roturl?Payment_title=' . rawurlencode( $this->title ) ),
				'Roturl_status' => IpnHandler::ROTURL_OK,
				'Remark'        => (string) $order->get_customer_note(),
			],
			$this->mtmk_extra_params( $order )
		);

		$url = Request::build_mtmk_url( $args );

		$order->update_status( 'pending', __( 'Waiting for the customer to complete the card authorization at SmilePay.', 'moksa-for-woocommerce' ) );
		$order->save();

		// 不手動 empty_cart — WC（含 Block checkout）在 process_payment 成功後自行清空。

		return [
			'result'   => 'success',
			'redirect' => $url,
		];
	}


	private function process_charge_flow( \WC_Order $order ): array {
		$args = array_merge(
			[
				'Pay_zg'        => $this->pay_zg(),
				'Pur_name'      => $order->get_billing_last_name() . $order->get_billing_first_name(),
				'Tel_number'    => (string) $order->get_billing_phone(),
				'Mobile_number' => (string) $order->get_billing_phone(),
				'Address'       => (string) $order->get_billing_address_1(),
				'Email'         => (string) $order->get_billing_email(),
				'Data_id'       => (string) $order->get_id(),
				'od_sob'        => Helper::build_products_summary( $order, 45 ),
				'Amount'        => (string) (int) ceil( (float) $order->get_total() ),
				'Roturl'        => home_url( '/wc-api/moksafowo_smilepay_roturl' ),
				'Roturl_status' => IpnHandler::ROTURL_OK,
				'Remark'        => (string) $order->get_customer_note(),
			],
			$this->extra_params( $order )
		);

		$resp = Request::create_order( $args );

		Helper::log(
			'charge flow result',
			[
				'order_id' => $order->get_id(),
				'gateway'  => $this->id,
				'pay_zg'   => $this->pay_zg(),
				'ok'       => $resp['ok'],
				'status'   => $resp['status'],
			]
		);

		if ( ! $resp['ok'] ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: error message */
					__( 'The SmilePay payment could not be created: %s', 'moksa-for-woocommerce' ),
					$resp['message']
				),
				'error'
			);
			$order->add_order_note(
				sprintf(
				/* translators: %s: error message */
					__( 'SmilePay could not issue a payment code: %s', 'moksa-for-woocommerce' ),
					$resp['message']
				)
			);
			$order->update_status( 'failed' );
			$order->save();
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}

		$this->store_charge_meta( $order, $resp['data'] );
		$order->update_status( 'on-hold', __( 'SmilePay issued the payment details and is waiting for the customer to pay.', 'moksa-for-woocommerce' ) );
		$order->save();

		// 不手動 empty_cart — WC（含 Block checkout）在 process_payment 成功後自行清空。

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		];
	}

	private function store_charge_meta( \WC_Order $order, array $data ): void {
		$smilepay_no = (string) ( $data['SmilePayNO'] ?? '' );
		$amount      = (string) ( $data['Amount'] ?? (int) ceil( (float) $order->get_total() ) );
		$pay_end     = (string) ( $data['PayEndDate'] ?? '' );

		$order->update_meta_data( Keys::SMILEPAY_PAY_SMILEPAY_NO, $smilepay_no );
		$order->update_meta_data( Keys::SMILEPAY_PAY_AMOUNT, $amount );
		$order->update_meta_data( Keys::SMILEPAY_PAY_END_DATE, $pay_end );

		$lines = [
			sprintf( /* translators: %s: pay method */ __( 'Payment method: %s', 'moksa-for-woocommerce' ), $this->title ),
		];

		switch ( $this->pay_zg() ) {
			case '2': // ATM.
				$bank = (string) ( $data['AtmBankNo'] ?? '' );
				$acct = (string) ( $data['AtmNo'] ?? '' );
				$order->update_meta_data( Keys::SMILEPAY_PAY_ATM_BANK_NO, $bank );
				$order->update_meta_data( Keys::SMILEPAY_PAY_ATM_NO, $acct );
				// SmilePay 沙箱不回 AtmBankNo（正式環境才帶）— 空值不顯示空行。
				if ( '' !== $bank ) {
					$lines[] = sprintf( /* translators: %s: bank code */ __( 'Bank code: %s', 'moksa-for-woocommerce' ), $bank );
				}
				$lines[] = sprintf( /* translators: %s: virtual account */ __( 'Virtual account: %s', 'moksa-for-woocommerce' ), $acct );
				break;

			case '3': // 超商條碼.
				$b1 = (string) ( $data['Barcode1'] ?? '' );
				$b2 = (string) ( $data['Barcode2'] ?? '' );
				$b3 = (string) ( $data['Barcode3'] ?? '' );
				$order->update_meta_data( Keys::SMILEPAY_PAY_BARCODE_1, $b1 );
				$order->update_meta_data( Keys::SMILEPAY_PAY_BARCODE_2, $b2 );
				$order->update_meta_data( Keys::SMILEPAY_PAY_BARCODE_3, $b3 );
				/* translators: 1: barcode segment 1, 2: barcode segment 2, 3: barcode segment 3 */
				$lines[] = sprintf( __( 'Payment barcode: %1$s / %2$s / %3$s', 'moksa-for-woocommerce' ), $b1, $b2, $b3 );
				break;

			case '4': // ibon.
				$ibon = (string) ( $data['IbonNo'] ?? '' );
				$order->update_meta_data( Keys::SMILEPAY_PAY_IBON_NO, $ibon );
				$lines[] = sprintf( /* translators: %s: ibon code */ __( 'Payment code: %s', 'moksa-for-woocommerce' ), $ibon );
				break;

			case '6': // FamiPort.
				$fami = (string) ( $data['FamiNO'] ?? '' );
				$order->update_meta_data( Keys::SMILEPAY_PAY_FAMI_NO, $fami );
				$lines[] = sprintf( /* translators: %s: famiport code */ __( 'Payment code: %s', 'moksa-for-woocommerce' ), $fami );
				break;
		}

		$lines[] = sprintf( /* translators: %s: amount */ __( 'Amount due: NT$%s', 'moksa-for-woocommerce' ), $amount );
		if ( '' !== $pay_end ) {
			$lines[] = sprintf( /* translators: %s: deadline */ __( 'Pay before: %s', 'moksa-for-woocommerce' ), $pay_end );
		}

		$html = '<div>';
		foreach ( $lines as $line ) {
			$html .= '<p>' . esc_html( $line ) . '</p>';
		}
		$html .= '</div>';

		$order->update_meta_data( Keys::SMILEPAY_PAY_INFO_HTML, $html );
		$order->add_order_note( wp_kses_post( $html ), 1 );
		if ( '' !== $smilepay_no ) {
			$order->add_order_note(
				sprintf(
				/* translators: %s: tracking code */
					__( 'SmilePay payment tracking code: %s', 'moksa-for-woocommerce' ),
					$smilepay_no
				)
			);
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$order->add_order_note(
				sprintf(
				/* translators: 1: amount, 2: reason */
					__( 'SmilePay refunds are made in the SmilePay dashboard (NT$%1$s) — %2$s', 'moksa-for-woocommerce' ),
					(int) ceil( (float) $amount ),
					'' !== (string) $reason ? $reason : __( 'No reason given', 'moksa-for-woocommerce' )
				)
			);
			$order->save();
		}
		return new \WP_Error(
			'moksafowo_smilepay_manual_refund',
			__( 'SmilePay refunds are made in the SmilePay dashboard.', 'moksa-for-woocommerce' )
		);
	}
}
