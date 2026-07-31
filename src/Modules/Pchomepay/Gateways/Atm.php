<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Pchomepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Atm extends AbstractPchomepayGateway {

	public const GATEWAY_ID = 'moksafowo_pchomepay_atm';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_types(): array {
		return [ 'ATM' ];
	}

	protected function min_amount(): int {
		return 1;
	}

	protected function max_amount(): int {
		return 49999;
	}

	protected function build_method_title(): string {
		return __( 'PChomePay ATM virtual account', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Transfer at an ATM before the deadline, using the virtual account number.', 'moksa-for-woocommerce' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'moksafowo_pchomepay_atm_expire_days', 5 );
		$days = max( 1, min( 5, $days ) );
		return [ 'atm_info' => [ 'expire_days' => $days ] ];
	}
}
