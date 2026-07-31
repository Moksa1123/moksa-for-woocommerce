<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class LinePay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_linepay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'LINEPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay LINE Pay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay with LINE Pay. The customer is redirected to the NewebPay payment page.', 'moksa-for-woocommerce' );
	}
}
