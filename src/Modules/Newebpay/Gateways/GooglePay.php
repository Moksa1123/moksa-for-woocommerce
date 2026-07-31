<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class GooglePay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_googlepay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'ANDROIDPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay Google Pay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay quickly with Google Pay, which needs Android or Chrome. The customer is redirected to the NewebPay payment page.', 'moksa-for-woocommerce' );
	}
}
