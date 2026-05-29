<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class UnionPay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'mo_newebpay_unionpay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'UNIONPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 銀聯卡', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '銀聯卡（UnionPay 國際）跨境付款。跳轉至藍新支付頁完成；接受 PRC / 港澳 / 海外發行的銀聯卡。', 'mo-ectools' );
	}
}
