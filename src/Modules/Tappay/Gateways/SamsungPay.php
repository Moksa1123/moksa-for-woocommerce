<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Gateways;

defined( 'ABSPATH' ) || exit;

final class SamsungPay extends AbstractDeviceWalletGateway {

	public const GATEWAY_ID = 'moksafowo_tappay_samsungpay';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	public function sdk_namespace(): string {
		return 'samsungPay';
	}

	protected function default_title(): string {
		return __( 'TapPay — Samsung Pay', 'moksa-for-woocommerce' );
	}

	protected function default_description(): string {
		return __( 'Pay with Samsung Pay. Only available on a Samsung device.', 'moksa-for-woocommerce' );
	}
}
