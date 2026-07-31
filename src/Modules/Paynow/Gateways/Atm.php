<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow\Gateways;

defined( 'ABSPATH' ) || exit;

final class Atm extends AbstractPaynowGateway {

	public const GATEWAY_ID = 'moksafowo_paynow_atm';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_type(): string {
		return '03';
	}

	protected function build_method_title(): string {
		return __( 'PayNow ATM virtual account', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Transfer at an ATM before the deadline, using the virtual account number.', 'moksa-for-woocommerce' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$params = [ 'AtmRespost' => '1' ];
		$days   = (int) get_option( 'moksafowo_paynow_atm_deadline_days', 0 );
		if ( $days > 0 ) {
			$params['DeadLine'] = $days;
		}
		return $params;
	}
}
