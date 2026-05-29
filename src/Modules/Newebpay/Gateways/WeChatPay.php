<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class WeChatPay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'mo_newebpay_wechatpay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'EZPWECHAT' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 微信支付', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '透過藍新中介整合的微信支付通道（接陸客與跨境訂單），跳轉至藍新支付頁完成。', 'mo-ectools' );
	}
}
