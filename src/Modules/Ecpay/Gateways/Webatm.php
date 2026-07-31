<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Webatm extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_webatm';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'WebATM';
	}

	protected function build_method_title(): string {
		return __( 'ECPay WebATM', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay by instant transfer using a chip debit card and a card reader.', 'moksa-for-woocommerce' );
	}
}
