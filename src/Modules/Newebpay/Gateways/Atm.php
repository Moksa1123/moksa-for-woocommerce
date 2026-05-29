<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Atm extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'mo_newebpay_atm';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'VACC' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 ATM 虛擬帳號', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '取得虛擬帳號後 3 天內至 ATM 轉帳完成付款。', 'mo-ectools' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'mo_newebpay_atm_expire_days', 3 );
		$days = max( 1, min( 180, $days ) );
		return [ 'ExpireDate' => gmdate( 'Ymd', time() + $days * DAY_IN_SECONDS ) ];
	}
}
