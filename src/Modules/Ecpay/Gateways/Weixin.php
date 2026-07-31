<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Weixin extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_weixin';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'WeiXin';
	}

	protected function build_method_title(): string {
		return __( 'ECPay WeChat Pay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Pay with the WeChat wallet.', 'moksa-for-woocommerce' );
	}
}
