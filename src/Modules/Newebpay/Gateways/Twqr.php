<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Twqr extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_twqr';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'TWQR' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay TWQR mobile payment', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'The shared QR code standard covering wallets such as JKOPAY, PXPay Plus and Pi Wallet. The customer is redirected to the NewebPay payment page.', 'moksa-for-woocommerce' );
	}
}
