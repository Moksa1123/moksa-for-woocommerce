<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Atm extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_atm';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'VACC' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay ATM virtual account', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Transfer at an ATM within 3 days of receiving the virtual account number.', 'moksa-for-woocommerce' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'moksafowo_newebpay_atm_expire_days', 3 );
		$days = max( 1, min( 180, $days ) );
		return [ 'ExpireDate' => gmdate( 'Ymd', time() + $days * DAY_IN_SECONDS ) ];
	}
}
