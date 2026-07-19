<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Cvs extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_cvs';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'CVS';
	}

	protected function build_method_title(): string {
		return __( '綠界 超商代碼', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( '取得繳費代碼後 7 天內，至 7-11 / 全家 / 萊爾富 / OK 超商繳費。', 'moksa-for-woocommerce' );
	}

	protected function extra_aio_params( \WC_Order $order ): array {
		return [
			'StoreExpireDate' => max( 1, min( 7, (int) get_option( 'moksafowo_ecpay_cvs_expire_days', 7 ) ) ),
			'PaymentInfoURL'  => home_url( '/wc-api/moksafowo_ecpay_payment' ),
		];
	}
}
