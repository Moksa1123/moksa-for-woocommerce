<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class ApplePay extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_applepay';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'ApplePay';
	}

	protected function build_method_title(): string {
		return __( 'ECPay Apple Pay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Check out quickly with Apple Pay. Requires Safari or a device that supports Apple Pay.', 'moksa-for-woocommerce' );
	}

	protected function supports_credit_action(): bool {
		return true;
	}
}
