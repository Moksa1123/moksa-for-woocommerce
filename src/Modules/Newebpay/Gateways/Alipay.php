<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Alipay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_alipay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'EZPALIPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay Alipay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay across borders with Alipay. The customer is redirected to the NewebPay payment page.', 'moksa-for-woocommerce' );
	}
}
