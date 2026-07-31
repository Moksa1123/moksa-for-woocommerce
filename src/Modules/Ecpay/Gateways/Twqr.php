<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Twqr extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_twqr';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'TWQR';
	}

	protected function build_method_title(): string {
		return __( 'ECPay TWQR mobile payment', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Scan to pay with a TWQR wallet such as Taiwan Pay or E.SUN Wallet.', 'moksa-for-woocommerce' );
	}

	protected function supports_credit_action(): bool {
		return true;
	}
}
