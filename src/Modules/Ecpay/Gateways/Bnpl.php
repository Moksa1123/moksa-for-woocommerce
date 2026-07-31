<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Bnpl extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_bnpl';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'BNPL';
	}

	protected function build_method_title(): string {
		return __( 'ECPay buy now, pay later (Yufu Digital / Zingala)', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay in instalments without a credit card. On the ECPay payment page the customer chooses either Yufu Digital or Zingala. Applicants must be at least 20 years old, and no credit check is required.', 'moksa-for-woocommerce' );
	}

	protected function supports_credit_action(): bool {
		return true;
	}
}
