<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class EsunWallet extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_esunwallet';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'ESUNWALLET' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 玉山 Wallet', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '玉山銀行行動支付（Wallet 綁定金融卡 / 信用卡），跳轉至藍新支付頁完成。', 'mo-ectools' );
	}
}
