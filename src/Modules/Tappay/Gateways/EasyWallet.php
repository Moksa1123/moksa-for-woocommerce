<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Gateways;

defined( 'ABSPATH' ) || exit;

final class EasyWallet extends AbstractWalletGateway {

	public const GATEWAY_ID = 'moksafowo_tappay_easywallet';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	public function sdk_namespace(): string {
		return 'easyWallet';
	}

	protected function default_title(): string {
		return __( 'TapPay — Easy Wallet', 'moksa-for-woocommerce' );
	}

	protected function default_description(): string {
		return __( 'Pay with Easy Wallet. You will be taken to Easy Wallet to confirm.', 'moksa-for-woocommerce' );
	}
}
