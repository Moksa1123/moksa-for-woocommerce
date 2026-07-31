<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Aftee extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_aftee';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'AFTEE' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay AFTEE buy now, pay later', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Buy now and pay later with AFTEE over up to 3 instalments, without a credit card. The customer is redirected to the NewebPay payment page.', 'moksa-for-woocommerce' );
	}

	protected function min_amount(): int {
		return 1000;
	}

	protected function max_amount(): int {
		return 50000;
	}
}
