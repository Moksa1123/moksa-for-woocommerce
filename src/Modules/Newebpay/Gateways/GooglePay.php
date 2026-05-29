<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class GooglePay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'mo_newebpay_googlepay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'ANDROIDPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 Google Pay', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( 'Android / Chrome 上使用 Google Pay 一鍵付款（信用卡 token 化），跳轉至藍新支付頁完成。', 'mo-ectools' );
	}
}
