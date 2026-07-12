<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Twqr extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_twqr';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'TWQR' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 TWQR 行動支付', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '跨業共通 QR Code 行動支付（街口 / 全盈 / Pi 拍錢包等多家整合），跳轉至藍新支付頁完成。', 'mo-ectools' );
	}
}
