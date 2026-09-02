<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Gateways;

defined( 'ABSPATH' ) || exit;

final class ApplePay extends AbstractDeviceWalletGateway {

	public const GATEWAY_ID = 'moksafowo_tappay_applepay';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	public function sdk_namespace(): string {
		return 'paymentRequestApi';
	}

	protected function default_title(): string {
		return __( 'TapPay — Apple Pay', 'moksa-for-woocommerce' );
	}

	protected function default_description(): string {
		return __( 'Pay with Apple Pay. Only available in Safari on an Apple device.', 'moksa-for-woocommerce' );
	}

	protected function extra_form_fields(): array {
		return [
			'merchant_identifier' => [
				'title'       => __( 'Apple Merchant ID', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'merchant.com.example.shop',
				'description' => __( 'From your Apple Developer account. You must also upload Apple\'s domain verification file to this site and enable Apple Pay in the TapPay Portal — without those the payment will fail.', 'moksa-for-woocommerce' ),
				'desc_tip'    => false,
			],
		];
	}

	public function merchant_identifier(): string {
		return trim( (string) $this->get_option( 'merchant_identifier', '' ) );
	}

	/** 沒填 Apple Merchant ID 就不顯示 —— 顯示了也必然失敗。 */
	protected function prerequisites_met(): bool {
		return '' !== $this->merchant_identifier();
	}
}
