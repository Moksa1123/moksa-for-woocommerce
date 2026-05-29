<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Credit extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'mo_ecpay_credit';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'Credit';
	}

	protected function build_method_title(): string {
		return __( '綠界 信用卡', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '使用信用卡一次付清，跳轉至綠界支付頁完成付款。', 'mo-ectools' );
	}

	protected function supports_credit_action(): bool {
		return true;
	}
}
