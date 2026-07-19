<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Bnpl extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_bnpl';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'BNPL';
	}

	protected function build_method_title(): string {
		return __( '綠界 無卡分期（裕富 / 中租）', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( '免信用卡分期付款。可由顧客在綠界付款頁選「裕富數位」或「中租銀角零卡」其中一家，年滿 20 歲且免聯徵即可申請。', 'moksa-for-woocommerce' );
	}

	protected function supports_credit_action(): bool {
		return true;
	}
}
