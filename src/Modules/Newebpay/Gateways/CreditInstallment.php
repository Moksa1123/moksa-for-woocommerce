<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class CreditInstallment extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_credit_installment';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		// CREDIT=1 (open credit) + InstFlag=逗號分隔期數
		return [
			'CREDIT'   => 1,
			'InstFlag' => $this->build_inst_flag(),
		];
	}

	protected function build_method_title(): string {
		return __( '藍新 信用卡分期', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( '信用卡分 3 / 6 / 12 / 18 / 24 / 30 期付款。可開期數依商家後台設定（需先向發卡銀行申請開通）。', 'moksa-for-woocommerce' );
	}

	public function init_form_fields(): void {
		parent::init_form_fields();
		$this->form_fields['installments'] = [
			'title'       => __( '允許分期期數', 'moksa-for-woocommerce' ),
			'type'        => 'multiselect',
			'class'       => 'wc-enhanced-select',
			'default'     => [],
			'options'     => [
				'3'  => __( '3 期', 'moksa-for-woocommerce' ),
				'6'  => __( '6 期', 'moksa-for-woocommerce' ),
				'12' => __( '12 期', 'moksa-for-woocommerce' ),
				'18' => __( '18 期', 'moksa-for-woocommerce' ),
				'24' => __( '24 期', 'moksa-for-woocommerce' ),
				'30' => __( '30 期', 'moksa-for-woocommerce' ),
			],
			'description' => __( '勾選要開放的分期期數。需先向發卡銀行申請開通。', 'moksa-for-woocommerce' ),
			'desc_tip'    => true,
		];
	}

	private function build_inst_flag(): string {
		$selected = $this->get_option( 'installments', [] );
		if ( ! is_array( $selected ) ) {
			$selected = [];
		}
		$valid = array_intersect( $selected, [ '3', '6', '12', '18', '24', '30' ] );
		return implode( ',', $valid );
	}
}
