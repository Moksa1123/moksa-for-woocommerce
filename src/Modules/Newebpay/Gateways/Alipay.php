<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Alipay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_alipay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'EZPALIPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 支付寶', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( '支付寶跨境付款，跳轉至藍新付款頁完成。', 'moksa-for-woocommerce' );
	}
}
