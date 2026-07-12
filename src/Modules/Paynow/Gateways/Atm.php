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
		return __( 'PayNow ATM 虛擬帳號', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '取得虛擬帳號後，於期限內至 ATM 轉帳完成付款。', 'mo-ectools' );
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
