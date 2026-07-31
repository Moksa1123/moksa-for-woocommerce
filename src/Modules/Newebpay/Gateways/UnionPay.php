<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class UnionPay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_unionpay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'UNIONPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay UnionPay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay across borders with UnionPay. The customer is redirected to the NewebPay payment page.', 'moksa-for-woocommerce' );
	}
}
