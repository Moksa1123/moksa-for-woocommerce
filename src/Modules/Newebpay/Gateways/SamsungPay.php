<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class SamsungPay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_samsungpay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'SAMSUNGPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 Samsung Pay', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( 'Samsung 手機上使用 Samsung Pay 付款，跳轉至藍新付款頁完成。', 'mo-ectools' );
	}
}
