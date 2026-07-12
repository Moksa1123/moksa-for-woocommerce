<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Smilepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Atm extends AbstractSmilepayGateway {

	public const GATEWAY_ID = 'moksafowo_smilepay_atm';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_zg(): string {
		return '2';
	}

	protected function redirect_flow(): bool {
		return false;
	}

	protected function min_amount(): int {
		return 1;
	}

	protected function max_amount(): int {
		return 20000;
	}

	protected function build_method_title(): string {
		return __( 'SmilePay ATM 虛擬帳號', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '取得虛擬帳號後，於期限內至 ATM / 網銀轉帳完成付款。', 'mo-ectools' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'moksafowo_smilepay_atm_deadline_days', 7 );
		$days = max( 1, min( 720, $days ) );
		return [ 'Deadline_date' => gmdate( 'Y/m/d', strtotime( "+{$days} days" ) ?: time() ) ];
	}
}
