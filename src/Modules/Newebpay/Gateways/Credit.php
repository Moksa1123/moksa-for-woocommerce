<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Credit extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'mo_newebpay_credit';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'CREDIT' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 信用卡', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '使用信用卡一次付清，跳轉至藍新支付頁完成付款。', 'mo-ectools' );
	}
}
