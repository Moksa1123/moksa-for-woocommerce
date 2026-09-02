<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Gateways;

defined( 'ABSPATH' ) || exit;

final class GooglePay extends AbstractDeviceWalletGateway {

	public const GATEWAY_ID = 'moksafowo_tappay_googlepay';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	public function sdk_namespace(): string {
		return 'googlePay';
	}

	protected function default_title(): string {
		return __( 'TapPay — Google Pay', 'moksa-for-woocommerce' );
	}

	protected function default_description(): string {
		return __( 'Pay with Google Pay.', 'moksa-for-woocommerce' );
	}

	protected function extra_form_fields(): array {
		return [
			'google_merchant_id' => [
				'title'       => __( 'Google Merchant ID', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'From the Google Pay & Wallet Console. Sandbox works without it, but live payments do not. You must also register this site as an allowed origin with Google.', 'moksa-for-woocommerce' ),
				'desc_tip'    => false,
			],
			'merchant_name'      => [
				'title'       => __( 'Merchant name shown to the customer', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Shown in the Google Pay sheet. Defaults to your site title when left blank.', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
		];
	}

	public function google_merchant_id(): string {
		return trim( (string) $this->get_option( 'google_merchant_id', '' ) );
	}

	public function merchant_name(): string {
		$name = trim( (string) $this->get_option( 'merchant_name', '' ) );
		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}
}
