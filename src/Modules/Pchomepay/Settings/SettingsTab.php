<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Pchomepay\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( 'Merchant credentials', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Apply to PChomePay for an app ID and secret. There is no public test account, so test credentials have to be requested separately.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_pchomepay_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_pchomepay_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'While this is ticked every transaction runs against the test environment and no money changes hands. Untick it once you go live.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Test app ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_pchomepay_sandbox_app_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Test secret', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_pchomepay_sandbox_secret',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live app ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_pchomepay_app_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live secret', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_pchomepay_secret',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_pchomepay_section',
			],

			[
				'title' => __( 'Payment settings', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_pchomepay_misc_section',
			],
			[
				'title'    => __( 'Credit card instalment plans', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_pchomepay_card_installment',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( 'Separate with commas, for example 1,3,6,12,18,24. Leave blank to offer payment in full only.', 'moksa-for-woocommerce' ),
			],
			[
				'title'             => __( 'ATM payment window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_pchomepay_atm_expire_days',
				'type'              => 'number',
				'default'           => 5,
				'desc_tip'          => __( 'How many days the customer has to pay after receiving the virtual account number. PChomePay allows 1 to 5 days.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 5,
					'step' => 1,
				],
			],
			[
				'title'             => __( 'Convenience store code window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_pchomepay_bcode_expire_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( 'How many days the customer has to pay after receiving the payment code. PChomePay allows 1 to 7 days.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 7,
					'step' => 1,
				],
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_pchomepay_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Turn this on when investigating a problem order. The log lives under WooCommerce → Status → Logs.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_pchomepay_misc_section',
			],

			[
				'title' => __( 'Enabled payment methods', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Tick the PChomePay payment methods to show at checkout.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_pchomepay_methods_section',
			],
			[
				'title'   => __( 'Payment method', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_pchomepay_enabled_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'moksafowo_pchomepay_card'      => __( 'Credit card, including instalments', 'moksa-for-woocommerce' ),
					'moksafowo_pchomepay_pi'        => __( 'Pi Wallet', 'moksa-for-woocommerce' ),
					'moksafowo_pchomepay_atm'       => __( 'ATM virtual account', 'moksa-for-woocommerce' ),
					'moksafowo_pchomepay_barcode'   => __( 'Convenience store code payment', 'moksa-for-woocommerce' ),
					'moksafowo_pchomepay_cvs711'    => __( '7-ELEVEN pickup and pay', 'moksa-for-woocommerce' ),
					'moksafowo_pchomepay_cvsfamily' => __( 'FamilyMart pickup and pay', 'moksa-for-woocommerce' ),
					'moksafowo_pchomepay_cvshilife' => __( 'Hi-Life pickup and pay', 'moksa-for-woocommerce' ),
				],
				'desc'    => __( 'Only the ticked payment methods appear at checkout.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_pchomepay_methods_section',
			],
		];
	}
}
