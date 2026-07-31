<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class EsunWallet extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_esunwallet';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'ESUNWALLET' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay E.SUN Wallet', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'E.SUN Bank mobile payments, with a debit or credit card linked to the wallet. The customer is redirected to the NewebPay payment page.', 'moksa-for-woocommerce' );
	}
}
