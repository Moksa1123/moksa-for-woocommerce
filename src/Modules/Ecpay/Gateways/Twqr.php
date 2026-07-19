<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Twqr extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_twqr';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'TWQR';
	}

	protected function build_method_title(): string {
		return __( '綠界 TWQR 行動支付', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( '使用台灣 Pay / 玉山 Wallet 等 TWQR 聯盟錢包掃碼付款。', 'moksa-for-woocommerce' );
	}

	protected function supports_credit_action(): bool {
		return true;
	}
}
