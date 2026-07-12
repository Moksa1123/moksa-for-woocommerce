<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Jkopay extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_jkopay';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'DigitalPayment';
	}

	protected function build_method_title(): string {
		return __( '綠界 街口支付', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '使用街口支付電子錢包付款。', 'mo-ectools' );
	}

	protected function extra_aio_params( \WC_Order $order ): array {
		return [ 'ChooseSubPayment' => 'Jkopay' ];
	}
}
