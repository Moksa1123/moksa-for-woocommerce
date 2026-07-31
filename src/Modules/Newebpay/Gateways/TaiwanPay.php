<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class TaiwanPay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_taiwanpay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'TAIWANPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay Taiwan Pay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Scan to pay with Taiwan Pay. The customer is redirected to the NewebPay payment page.', 'moksa-for-woocommerce' );
	}
}
