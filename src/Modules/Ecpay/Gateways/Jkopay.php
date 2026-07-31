<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Jkopay extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_jkopay';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'DigitalPayment';
	}

	protected function build_method_title(): string {
		return __( 'ECPay JKOPAY', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay with the JKOPAY e-wallet.', 'moksa-for-woocommerce' );
	}

	protected function extra_aio_params( \WC_Order $order ): array {
		return [ 'ChooseSubPayment' => 'Jkopay' ];
	}
}
