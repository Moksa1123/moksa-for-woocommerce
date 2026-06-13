<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Webatm extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_webatm';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'WebATM';
	}

	protected function build_method_title(): string {
		return __( '綠界 網路 ATM', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '使用晶片金融卡 + 讀卡機，即時轉帳完成付款。', 'mo-ectools' );
	}
}
