<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Gateways;

defined( 'ABSPATH' ) || exit;

final class JkoPay extends AbstractWalletGateway {

	public const GATEWAY_ID = 'moksafowo_tappay_jkopay';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	public function sdk_namespace(): string {
		return 'jkoPay';
	}

	protected function default_title(): string {
		return __( 'TapPay — JKOPAY', 'moksa-for-woocommerce' );
	}

	protected function default_description(): string {
		return __( 'Pay with JKOPAY. You will be taken to JKOPAY to confirm.', 'moksa-for-woocommerce' );
	}
}
