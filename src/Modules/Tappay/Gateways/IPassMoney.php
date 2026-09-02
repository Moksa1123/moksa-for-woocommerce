<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Gateways;

defined( 'ABSPATH' ) || exit;

final class IPassMoney extends AbstractWalletGateway {

	public const GATEWAY_ID = 'moksafowo_tappay_ipassmoney';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	public function sdk_namespace(): string {
		return 'iPassMoney';
	}

	protected function default_title(): string {
		return __( 'TapPay — iPASS MONEY', 'moksa-for-woocommerce' );
	}

	protected function default_description(): string {
		return __( 'Pay with iPASS MONEY. You will be taken to iPASS MONEY to confirm.', 'moksa-for-woocommerce' );
	}
}
