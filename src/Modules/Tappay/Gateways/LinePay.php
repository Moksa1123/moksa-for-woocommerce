<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Gateways;

defined( 'ABSPATH' ) || exit;

final class LinePay extends AbstractWalletGateway {

	public const GATEWAY_ID = 'moksafowo_tappay_linepay';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	public function sdk_namespace(): string {
		return 'linePay';
	}

	protected function default_title(): string {
		return __( 'TapPay — LINE Pay', 'moksa-for-woocommerce' );
	}

	protected function default_description(): string {
		return __( 'Pay with LINE Pay. You will be taken to LINE Pay to confirm.', 'moksa-for-woocommerce' );
	}
}
