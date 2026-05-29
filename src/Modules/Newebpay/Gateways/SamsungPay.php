<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class SamsungPay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'mo_newebpay_samsungpay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'SAMSUNGPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 Samsung Pay', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( 'Samsung 手機上使用 Samsung Pay 信用卡 token 化付款，跳轉至藍新支付頁完成。', 'mo-ectools' );
	}
}
