<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Weixin extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'mo_ecpay_weixin';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'WeiXin';
	}

	protected function build_method_title(): string {
		return __( '綠界 微信支付', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '使用微信錢包付款。', 'mo-ectools' );
	}
}
