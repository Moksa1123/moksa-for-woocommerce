<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Gateways;

defined( 'ABSPATH' ) || exit;

final class PxPayPlus extends AbstractWalletGateway {

	public const GATEWAY_ID = 'moksafowo_tappay_pxpayplus';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	public function sdk_namespace(): string {
		return 'pxpayplus';
	}

	protected function default_title(): string {
		return __( 'TapPay — PXPay Plus', 'moksa-for-woocommerce' );
	}

	protected function default_description(): string {
		return __( 'Pay with PXPay Plus. You will be taken to PXPay Plus to confirm.', 'moksa-for-woocommerce' );
	}
}
