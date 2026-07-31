<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class CreditInstallment18 extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_credit_18';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'Credit';
	}

	protected function build_method_title(): string {
		return __( 'ECPay credit card, 18 instalments', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay by credit card over 18 instalments. The customer is redirected to the ECPay payment page.', 'moksa-for-woocommerce' );
	}

	protected function extra_aio_params( \WC_Order $order ): array {
		return [ 'CreditInstallment' => 18 ];
	}

	protected function supports_credit_action(): bool {
		return true;
	}
}
