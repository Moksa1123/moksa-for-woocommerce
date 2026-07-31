<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Smilepay\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( 'Merchant credentials', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Find Dcvc, Rvg2c, Verify_key and the merchant verification parameter (Mid) in the SmilePay dashboard under Member area → System identification data.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_smilepay_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'When ticked, transactions run through the SmilePay public test merchant (Dcvc=107) and no money changes hands. Untick it once you go live.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Dcvc — merchant code', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_dcvc',
				'type'  => 'text',
			],
			[
				'title' => __( 'Rvg2c — merchant parameter code', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_rvg2c',
				'type'  => 'text',
			],
			[
				'title' => __( 'Verify_key — merchant check code', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_verify_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Mid — merchant verification parameter', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_mid',
				'type'  => 'text',
				'desc'  => __( 'Used to confirm that payment notifications really came from SmilePay. Enter the same value as in the SmilePay dashboard. If it is blank every notification is rejected, so orders will never be marked as paid automatically.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_section',
			],

			[
				'title' => __( 'Payment settings', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_smilepay_misc_section',
			],
			[
				'title'    => __( 'Credit card instalment plans', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_smilepay_installment',
				'type'     => 'text',
				'default'  => '3',
				'desc_tip' => __( 'Separate with commas, for example 3,6,12. Card instalments use the first valid plan in the list.', 'moksa-for-woocommerce' ),
			],
			[
				'title'             => __( 'ATM payment window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_smilepay_atm_deadline_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( 'How many days the customer has to pay after receiving the virtual account number (1 to 720).', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 720,
					'step' => 1,
				],
			],
			[
				'title'             => __( 'Convenience store barcode window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_smilepay_barcode_deadline_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( 'How many days the customer has to pay after receiving the barcode (1 to 50).', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 50,
					'step' => 1,
				],
			],
			[
				'title'             => __( 'ibon payment window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_smilepay_ibon_deadline_days',
				'type'              => 'number',
				'default'           => 6,
				'desc_tip'          => __( 'How many days the customer has to pay after receiving the payment code (1 to 6).', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 6,
					'step' => 1,
				],
			],
			[
				'title'             => __( 'FamiPort payment window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_smilepay_famiport_deadline_days',
				'type'              => 'number',
				'default'           => 6,
				'desc_tip'          => __( 'How many days the customer has to pay after receiving the payment code (1 to 6).', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 6,
					'step' => 1,
				],
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_smilepay_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Turn this on when investigating a problem order. The log lives under WooCommerce → Status → Logs.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_misc_section',
			],

			[
				'title' => __( 'Enabled payment methods', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Tick the SmilePay payment methods to show at checkout. Their titles and descriptions are set on the WooCommerce → Payments tab.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_methods_section',
			],
			[
				'title'   => __( 'Payment method', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_smilepay_enabled_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'moksafowo_smilepay_credit'   => __( 'Credit card', 'moksa-for-woocommerce' ),
					'moksafowo_smilepay_credit_installment' => __( 'Credit card instalments', 'moksa-for-woocommerce' ),
					'moksafowo_smilepay_atm'      => __( 'ATM virtual account', 'moksa-for-woocommerce' ),
					'moksafowo_smilepay_barcode'  => __( 'Convenience store barcode', 'moksa-for-woocommerce' ),
					'moksafowo_smilepay_ibon'     => __( 'ibon code', 'moksa-for-woocommerce' ),
					'moksafowo_smilepay_famiport' => __( 'FamiPort code', 'moksa-for-woocommerce' ),
					'moksafowo_smilepay_unionpay' => __( 'UnionPay', 'moksa-for-woocommerce' ),
				],
				'desc'    => __( 'Only the ticked payment methods appear at checkout.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_methods_section',
			],
		];
	}
}
