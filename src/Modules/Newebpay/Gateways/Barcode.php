<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Barcode extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_barcode';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'BARCODE' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 超商條碼繳費', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '列印 3 段超商條碼到 7-11 / 全家 / 萊爾富 / OK 任一門市繳費。最低 20 元 / 最高 40,000 元（藍新限制）。', 'mo-ectools' );
	}

	protected function min_amount(): int {
		return 20;
	}

	protected function max_amount(): int {
		return 40000;
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'moksafowo_newebpay_barcode_expire_days', 7 );
		$days = max( 1, min( 180, $days ) );
		return [ 'ExpireDate' => gmdate( 'Ymd', time() + $days * DAY_IN_SECONDS ) ];
	}
}
