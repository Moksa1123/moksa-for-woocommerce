<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\NewebpayShipping\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( 'General settings', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Once the shipping credentials are in place, enable the shipping zones under WooCommerce → Settings → Shipping.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_newebpay_shipping_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'When ticked, everything runs against the test environment and nothing is really dispatched. Off by default; left blank it follows the NewebPay payments test mode setting.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Shipping type', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_newebpay_shipping_lgs_type',
				'type'     => 'select',
				'default'  => 'B2C',
				'options'  => [
					'B2C' => __( 'Merchant shipping (the default; the shipping account must be activated in the NewebPay dashboard)', 'moksa-for-woocommerce' ),
					'C2C' => __( 'Personal shipping', 'moksa-for-woocommerce' ),
				],
				'desc'     => __( 'This depends on which shipping services are activated in the NewebPay dashboard.', 'moksa-for-woocommerce' ),
				'desc_tip' => true,
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_shipping_section',
			],

			[
				'title' => __( 'Test credentials (optional)', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Leave blank to reuse the NewebPay payments test credentials. Fill these in only if your NewebPay shipping account is separate.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_sandbox_section',
			],
			[
				'title' => __( 'Test merchant ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_sandbox_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Test hash key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_sandbox_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Test hash IV', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_sandbox_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_shipping_sandbox_section',
			],

			[
				'title' => __( 'Live credentials (optional)', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Leave blank to reuse the NewebPay payments live credentials.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_prod_section',
			],
			[
				'title' => __( 'Live merchant ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash IV', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_shipping_prod_section',
			],

			[
				'title' => __( 'Enabled convenience stores', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Which convenience store chains customers can choose from at checkout.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_carriers_section',
			],
			[
				'title'   => __( 'Allowed store chains', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_newebpay_shipping_enabled_carriers',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'1' => __( '7-11', 'moksa-for-woocommerce' ),
					'2' => __( 'FamilyMart', 'moksa-for-woocommerce' ),
					'3' => __( 'Hi-Life', 'moksa-for-woocommerce' ),
					'4' => __( 'OK', 'moksa-for-woocommerce' ),
				],
				'default' => [ '1', '2', '3', '4' ],
				'desc'    => __( 'Leave blank to allow all four: 7-ELEVEN, FamilyMart, Hi-Life and OK Mart.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_shipping_carriers_section',
			],

			[
				'title' => __( 'Sender details', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'The sender details printed on convenience store labels, and used by the carrier to reach you on home deliveries.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_sender_section',
			],
			[
				'title' => __( 'Sender name', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_sender_name',
				'type'  => 'text',
				'desc'  => __( '1 to 10 Chinese characters, or 1 to 20 Latin characters.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Sender phone', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_sender_phone',
				'type'  => 'text',
				'desc'  => __( 'A landline or mobile number.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Sender mobile', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_sender_cellphone',
				'type'  => 'text',
				'desc'  => __( 'Worth filling in so NewebPay or the store can reach you.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Sender email', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_shipping_sender_email',
				'type'  => 'email',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_shipping_sender_section',
			],

			[
				'title' => __( 'Other', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_newebpay_shipping_misc_section',
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_newebpay_shipping_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Turn this on when investigating a problem shipment. The log lives under WooCommerce → Status → Logs.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_shipping_misc_section',
			],
		];
	}
}
