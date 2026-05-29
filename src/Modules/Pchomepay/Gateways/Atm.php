<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Pchomepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Atm extends AbstractPchomepayGateway {

	public const GATEWAY_ID = 'mo_pchomepay_atm';

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
		return __( '支付連 ATM 虛擬帳號', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '取得虛擬帳號後，於期限內至 ATM 轉帳完成付款。', 'mo-ectools' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'mo_pchomepay_atm_expire_days', 5 );
		$days = max( 1, min( 5, $days ) );
		return [ 'atm_info' => [ 'expire_days' => $days ] ];
	}
}
