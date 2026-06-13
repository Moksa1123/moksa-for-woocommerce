<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Webatm extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_webatm';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'WEBATM' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 WebATM 即時轉帳', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '使用網路 ATM 即時轉帳，需備網路銀行金融卡 + 讀卡機。', 'mo-ectools' );
	}
}
