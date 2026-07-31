<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( 'Merchant credentials', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Apply to PayNow for a WebNo — your business tax ID or personal ID — and a merchant transaction password. Live and test accounts are separate and cannot be shared.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_paynow_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_paynow_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'While this is ticked every transaction runs against the PayNow test platform. Note that on the test platform everything except virtual accounts comes back as failed. Untick it once you go live.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Test WebNo', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_paynow_sandbox_web_no',
				'type'     => 'text',
				'desc_tip' => __( 'The business tax ID or personal ID for the test environment. Any letters in a personal ID must be uppercase.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Test merchant transaction password', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_paynow_sandbox_trade_password',
				'type'  => 'text',
			],
			[
				'title'    => __( 'Live WebNo', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_paynow_web_no',
				'type'     => 'text',
				'desc_tip' => __( 'The business tax ID or personal ID for the live environment. Any letters in a personal ID must be uppercase.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Live merchant transaction password', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_paynow_trade_password',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_paynow_section',
			],

			[
				'title' => __( 'Payment settings', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_paynow_misc_section',
			],
			[
				'title'    => __( 'Platform name', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_paynow_ec_platform',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( 'The platform name sent to PayNow. Leave blank to use the site name.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Transaction description', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_paynow_order_info',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( '5 to 200 characters. Leave blank to use the order\'s item names.', 'moksa-for-woocommerce' ),
			],
			[
				'title'             => __( 'ATM payment window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_paynow_atm_deadline_days',
				'type'              => 'number',
				'default'           => 0,
				'desc_tip'          => __( 'How long the virtual account stays payable. Set 0 to use the PayNow default.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 0,
					'step' => 1,
				],
			],
			[
				'title'             => __( 'Convenience store barcode window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_paynow_cvs_deadline_days',
				'type'              => 'number',
				'default'           => 0,
				'desc_tip'          => __( 'How long the barcode stays payable. Set 0 to use the PayNow default.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 0,
					'step' => 1,
				],
			],
			[
				'title'             => __( 'Payment code window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_paynow_code_deadline_days',
				'type'              => 'number',
				'default'           => 0,
				'desc_tip'          => __( 'How long ibon, FamiPort and iCash codes stay payable. Set 0 to use the PayNow default.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 0,
					'step' => 1,
				],
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_paynow_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Turn this on when investigating a problem order. The log lives under WooCommerce → Status → Logs, and never contains passwords or other sensitive data.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_paynow_misc_section',
			],

			[
				'title' => __( 'Enabled payment methods', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Tick the PayNow payment methods to show at checkout.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_paynow_methods_section',
			],
			[
				'title'   => __( 'Payment method', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_paynow_enabled_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'moksafowo_paynow_credit'             => __( 'Credit card', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_credit_installment' => __( 'Credit card instalments', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_webatm'             => __( 'WebATM', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_atm'                => __( 'ATM virtual account', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_cvs'                => __( 'Convenience store barcode payment', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_ibon'               => __( 'ibon code', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_famiport'           => __( 'FamiPort code', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_icash'              => __( 'iCash wallet', 'moksa-for-woocommerce' ),
					'moksafowo_paynow_unionpay'           => __( 'UnionPay', 'moksa-for-woocommerce' ),
				],
				'desc'    => __( 'Only the ticked payment methods appear at checkout.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_paynow_methods_section',
			],
		];
	}
}
