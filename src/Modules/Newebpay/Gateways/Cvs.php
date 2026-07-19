<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Cvs extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_cvs';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'CVS' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 超商代碼繳費', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( '取得超商代碼後到 7-11 / 全家 / 萊爾富 / OK 任一門市繳費。最低 30 元 / 最高 20,000 元（藍新限制）。', 'moksa-for-woocommerce' );
	}

	protected function min_amount(): int {
		return 30;
	}

	protected function max_amount(): int {
		return 20000;
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'moksafowo_newebpay_cvs_expire_days', 7 );
		$days = max( 1, min( 180, $days ) );
		return [ 'ExpireDate' => gmdate( 'Ymd', time() + $days * DAY_IN_SECONDS ) ];
	}
}
