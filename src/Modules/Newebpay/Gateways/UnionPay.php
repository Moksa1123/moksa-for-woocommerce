<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class UnionPay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_unionpay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'UNIONPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 銀聯卡', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( '銀聯卡跨境付款，跳轉至藍新付款頁完成。', 'moksa-for-woocommerce' );
	}
}
