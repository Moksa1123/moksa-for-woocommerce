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
			'title'       => __( '下單成功顯示訊息', 'mo-ectools' ),
			'type'        => 'textarea',
			'default'     => '',
			'description' => __( '顧客完成下單後在感謝頁額外顯示的訊息（選填）。', 'mo-ectools' ),
			'desc_tip'    => true,
		];
		return $fields;
	}


	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( esc_html__( '找不到訂單', 'mo-ectools' ) );
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

		$order->update_status( 'pending', __( '等待顧客於 SmilePay 完成信用卡授權。', 'mo-ectools' ) );
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
					__( '無法建立 SmilePay 付款：%s', 'mo-ectools' ),
					$resp['message']
				),
				'error'
			);
			$order->add_order_note(
				sprintf(
				/* translators: %s: error message */
					__( 'SmilePay 取號失敗：%s', 'mo-ectools' ),
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
		$order->update_status( 'on-hold', __( 'SmilePay 已產生繳費資訊，等待顧客付款。', 'mo-ectools' ) );
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
			sprintf( /* translators: %s: pay method */ __( '繳費方式：%s', 'mo-ectools' ), $this->title ),
		];

		switch ( $this->pay_zg() ) {
			case '2': // ATM.
				$bank = (string) ( $data['AtmBankNo'] ?? '' );
				$acct = (string) ( $data['AtmNo'] ?? '' );
				$order->update_meta_data( Keys::SMILEPAY_PAY_ATM_BANK_NO, $bank );
				$order->update_meta_data( Keys::SMILEPAY_PAY_ATM_NO, $acct );
				// SmilePay 沙箱不回 AtmBankNo（正式環境才帶）— 空值不顯示空行。
				if ( '' !== $bank ) {
					$lines[] = sprintf( /* translators: %s: bank code */ __( '銀行代號：%s', 'mo-ectools' ), $bank );
				}
				$lines[] = sprintf( /* translators: %s: virtual account */ __( '虛擬帳號：%s', 'mo-ectools' ), $acct );
				break;

			case '3': // 超商條碼.
				$b1 = (string) ( $data['Barcode1'] ?? '' );
				$b2 = (string) ( $data['Barcode2'] ?? '' );
				$b3 = (string) ( $data['Barcode3'] ?? '' );
				$order->update_meta_data( Keys::SMILEPAY_PAY_BARCODE_1, $b1 );
				$order->update_meta_data( Keys::SMILEPAY_PAY_BARCODE_2, $b2 );
				$order->update_meta_data( Keys::SMILEPAY_PAY_BARCODE_3, $b3 );
				/* translators: 1: barcode segment 1, 2: barcode segment 2, 3: barcode segment 3 */
				$lines[] = sprintf( __( '繳費條碼：%1$s / %2$s / %3$s', 'mo-ectools' ), $b1, $b2, $b3 );
				break;

			case '4': // ibon.
				$ibon = (string) ( $data['IbonNo'] ?? '' );
				$order->update_meta_data( Keys::SMILEPAY_PAY_IBON_NO, $ibon );
				$lines[] = sprintf( /* translators: %s: ibon code */ __( '繳費代碼：%s', 'mo-ectools' ), $ibon );
				break;

			case '6': // FamiPort.
				$fami = (string) ( $data['FamiNO'] ?? '' );
				$order->update_meta_data( Keys::SMILEPAY_PAY_FAMI_NO, $fami );
				$lines[] = sprintf( /* translators: %s: famiport code */ __( '繳費代碼：%s', 'mo-ectools' ), $fami );
				break;
		}

		$lines[] = sprintf( /* translators: %s: amount */ __( '繳費金額：NT$%s', 'mo-ectools' ), $amount );
		if ( '' !== $pay_end ) {
			$lines[] = sprintf( /* translators: %s: deadline */ __( '繳費期限：%s', 'mo-ectools' ), $pay_end );
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
					__( 'SmilePay 金流追蹤碼：%s', 'mo-ectools' ),
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
					__( 'SmilePay 退款請至 SmilePay 商家後台手動操作（金額 NT$%1$s）— %2$s', 'mo-ectools' ),
					(int) ceil( (float) $amount ),
					'' !== (string) $reason ? $reason : __( '無原因', 'mo-ectools' )
				)
			);
			$order->save();
		}
		return new \WP_Error(
			'moksafowo_smilepay_manual_refund',
			__( 'SmilePay 退款請至 SmilePay 商家後台手動操作。', 'mo-ectools' )
		);
	}
}
