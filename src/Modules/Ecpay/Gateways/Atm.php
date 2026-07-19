<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Atm extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_atm';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'ATM';
	}

	protected function build_method_title(): string {
		return __( '綠界 ATM 轉帳', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( '取得虛擬帳號後 3 天內，可至 ATM 或網路銀行轉帳。', 'moksa-for-woocommerce' );
	}

	protected function extra_aio_params( \WC_Order $order ): array {
		return [
			'ExpireDate'     => max( 1, min( 60, (int) get_option( 'moksafowo_ecpay_atm_expire_days', 3 ) ) ),
			'PaymentInfoURL' => home_url( '/wc-api/moksafowo_ecpay_payment' ),
		];
	}
}
