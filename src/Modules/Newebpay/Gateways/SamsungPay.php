<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class SamsungPay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_samsungpay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'SAMSUNGPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay Samsung Pay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay with Samsung Pay on a Samsung phone. The customer is redirected to the NewebPay payment page.', 'moksa-for-woocommerce' );
	}
}
