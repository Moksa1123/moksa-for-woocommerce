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
		return __( 'NewebPay credit card instalments', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay by credit card over 3, 6, 12, 18, 24 or 30 instalments. Which plans are available depends on your settings, and each must be approved by the card issuer first.', 'moksa-for-woocommerce' );
	}

	public function init_form_fields(): void {
		parent::init_form_fields();
		$this->form_fields['installments'] = [
			'title'       => __( 'Allowed instalment plans', 'moksa-for-woocommerce' ),
			'type'        => 'multiselect',
			'class'       => 'wc-enhanced-select',
			'default'     => [],
			'options'     => [
				'3'  => __( '3 instalments', 'moksa-for-woocommerce' ),
				'6'  => __( '6 instalments', 'moksa-for-woocommerce' ),
				'12' => __( '12 instalments', 'moksa-for-woocommerce' ),
				'18' => __( '18 instalments', 'moksa-for-woocommerce' ),
				'24' => __( '24 instalments', 'moksa-for-woocommerce' ),
				'30' => __( '30 instalments', 'moksa-for-woocommerce' ),
			],
			'description' => __( 'Tick the instalment plans to offer. Each must be approved by the card issuer first.', 'moksa-for-woocommerce' ),
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
