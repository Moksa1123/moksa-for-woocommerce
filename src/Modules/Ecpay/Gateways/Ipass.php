<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Ipass extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_ipass';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'DigitalPayment';
	}

	protected function build_method_title(): string {
		return __( '綠界 一卡通 iPASS', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( '使用一卡通 iPASS Money 電子錢包付款。', 'moksa-for-woocommerce' );
	}

	protected function extra_aio_params( \WC_Order $order ): array {
		return [ 'ChooseSubPayment' => 'iPASS' ];
	}
}
