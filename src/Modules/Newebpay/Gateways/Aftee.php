<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Aftee extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_aftee';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'AFTEE' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 AFTEE 無卡分期', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( 'AFTEE 先享後付無卡分期，最多 3 期，跳轉至藍新付款頁完成。', 'mo-ectools' );
	}

	protected function min_amount(): int {
		return 1000;
	}

	protected function max_amount(): int {
		return 50000;
	}
}
