<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class ApplePay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_applepay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'APPLEPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay Apple Pay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay quickly with Apple Pay, which needs Safari or an iOS device. The customer is redirected to the NewebPay payment page.', 'moksa-for-woocommerce' );
	}
}
