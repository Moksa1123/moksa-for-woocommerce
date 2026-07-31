<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Credit extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_credit';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'CREDIT' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay credit card', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay in full by credit card. The customer is redirected to the NewebPay payment page.', 'moksa-for-woocommerce' );
	}
}
