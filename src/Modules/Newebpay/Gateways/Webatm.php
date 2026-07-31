<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Webatm extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_webatm';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'WEBATM' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay WebATM instant transfer', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay by instant transfer through WebATM, which needs a debit card and a card reader.', 'moksa-for-woocommerce' );
	}
}
