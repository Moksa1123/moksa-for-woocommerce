<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( 'Merchant credentials', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Copy these from the NewebPay dashboard under Store settings. NewebPay has no public test account, so you need to apply for a test environment yourself.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_newebpay_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'For use before going live. While this is ticked every transaction runs against the test environment and no money changes hands. Untick it once you go live.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Test merchant ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_sandbox_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Test hash key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_sandbox_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Test hash IV', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_sandbox_hash_iv',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live merchant ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash IV', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_section',
			],

			[
				'title' => __( 'Orders and products', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_newebpay_misc_section',
			],
			[
				'title'             => __( 'Order number prefix', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_newebpay_order_prefix',
				'type'              => 'text',
				'default'           => '',
				'desc'              => __( 'Up to 5 letters or digits. Leave blank and the order number is used on its own.', 'moksa-for-woocommerce' ),
				'desc_tip'          => true,
				'custom_attributes' => [
					'pattern'   => '[A-Za-z0-9]{0,5}',
					'maxlength' => 5,
				],
			],
			[
				'title'    => __( 'Product name shown on the payment page', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_newebpay_payment_item_name',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( 'Leave blank to use the first product in the order, which we recommend. Enter fixed text, such as “Online goods”, and every order shows the same wording.', 'moksa-for-woocommerce' ),
			],
			[
				'title'             => __( 'ATM payment window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_newebpay_atm_expire_days',
				'type'              => 'number',
				'default'           => 3,
				'desc_tip'          => __( 'How many days the customer has to pay after choosing ATM before the order expires. NewebPay allows up to 60 days.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 60,
					'step' => 1,
				],
			],
			[
				'title'             => __( 'Convenience store code window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_newebpay_cvs_expire_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( 'How long the customer has to pay with the code at a convenience store. NewebPay allows 1 to 30 days.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 30,
					'step' => 1,
				],
			],
			[
				'title'             => __( 'Convenience store barcode window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_newebpay_barcode_expire_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( 'How long the customer has to pay with the barcode at a convenience store. NewebPay allows 1 to 30 days.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 30,
					'step' => 1,
				],
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_newebpay_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Turn this on when investigating a problem order. The log lives under WooCommerce → Status → Logs.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_misc_section',
			],

			[
				'title' => __( 'How the checkout looks', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Choose whether the customer sees one button that goes to NewebPay to pick a method, or a separate button for each payment method.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_newebpay_display_setting',
			],
			[
				'title'   => __( 'Display style', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_newebpay_display_mode',
				'type'    => 'select',
				'css'     => 'width: 400px;',
				'options' => [
					'multi'  => __( 'Separate — one button per payment method (recommended)', 'moksa-for-woocommerce' ),
					'single' => __( 'Combined — a single NewebPay button that leads to the checkout page', 'moksa-for-woocommerce' ),
				],
				'default' => 'multi',
			],
			[
				'title'   => __( 'Enabled payment methods', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_newebpay_enabled_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'moksafowo_newebpay_credit'     => __( 'Credit card (pay in full)', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_credit_installment' => __( 'Credit card instalments (3, 6, 12, 18 or 24)', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_atm'        => __( 'ATM virtual account', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_webatm'     => __( 'WebATM', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_cvs'        => __( 'Convenience store code', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_barcode'    => __( 'Convenience store barcode', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_applepay'   => __( 'Apple Pay', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_googlepay'  => __( 'Google Pay', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_samsungpay' => __( 'Samsung Pay', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_linepay'    => __( 'LINE Pay (through NewebPay)', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_esunwallet' => __( 'E.SUN Wallet', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_taiwanpay'  => __( 'Taiwan Pay', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_twqr'       => __( 'TWQR mobile payment', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_alipay'     => __( 'Alipay', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_wechatpay'  => __( 'WeChat Pay', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_aftee'      => __( 'AFTEE buy now, pay later', 'moksa-for-woocommerce' ),
					'moksafowo_newebpay_unionpay'   => __( 'UnionPay', 'moksa-for-woocommerce' ),
				],
				'desc'    => __( 'Only the ticked payment methods appear at checkout. This has no effect in combined display mode. AFTEE, UnionPay and the cross-border methods also have to be activated in the NewebPay dashboard before they show up.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_newebpay_display_setting',
			],
		];
	}
}
