<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Settings;

use Moksafowo\Modules\Ecpay\Api\Helper;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( 'Merchant credentials', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Copy these from the ECPay dashboard under Merchant area → System identification data. In test mode you can use the public test account that is filled in by default.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'For use before going live. While this is ticked every transaction runs against the test environment and no money changes hands. Untick it once you go live.', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( 'Test merchant ID', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_sandbox_merchant_id',
				'type'    => 'text',
				'default' => Helper::SANDBOX_MERCHANT_ID,
			],
			[
				'title'   => __( 'Test hash key', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_sandbox_hash_key',
				'type'    => 'text',
				'default' => Helper::SANDBOX_HASH_KEY,
			],
			[
				'title'   => __( 'Test hash IV', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_sandbox_hash_iv',
				'type'    => 'text',
				'default' => Helper::SANDBOX_HASH_IV,
			],
			[
				'title' => __( 'Live merchant ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash IV', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_section',
			],

			[
				'title' => __( 'Orders and products', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_ecpay_misc_section',
			],
			[
				'title'             => __( 'Order number prefix', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_ecpay_order_prefix',
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
				'id'       => 'moksafowo_ecpay_payment_item_name',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( 'Leave blank to use the first product in the order, which we recommend. Enter fixed text, such as “Online goods”, and every order shows the same wording.', 'moksa-for-woocommerce' ),
			],
			[
				'title'             => __( 'ATM payment window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_ecpay_atm_expire_days',
				'type'              => 'number',
				'default'           => 3,
				'desc_tip'          => __( 'How many days the customer has to pay after choosing ATM before the order expires. ECPay allows 1 to 60 days.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 60,
					'step' => 1,
				],
			],
			[
				'title'             => __( 'Convenience store code window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_ecpay_cvs_expire_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( 'How long the customer has to pay with the code at a convenience store. ECPay allows 1 to 7 days.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 7,
					'step' => 1,
				],
			],
			[
				'title'             => __( 'Convenience store barcode window (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_ecpay_barcode_expire_days',
				'type'              => 'number',
				'default'           => 7,
				'desc_tip'          => __( 'How long the customer has to pay with the barcode at a convenience store. ECPay allows 1 to 7 days.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 7,
					'step' => 1,
				],
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Turn this on when investigating a problem order. The log lives under WooCommerce → Status → Logs.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_misc_section',
			],

			[
				'title' => __( 'How the checkout looks', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Choose whether the customer sees one button that goes to ECPay to pick a method, or a separate button for each payment method.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_display_setting',
			],
			[
				'title'   => __( 'Display style', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_display_mode',
				'type'    => 'select',
				'css'     => 'width: 400px;',
				'options' => [
					'multi'  => __( 'Separate — one button per payment method (recommended)', 'moksa-for-woocommerce' ),
					'single' => __( 'Combined — a single ECPay button that leads to the checkout page', 'moksa-for-woocommerce' ),
				],
				'default' => 'multi',
			],
			[
				'title'   => __( 'Enabled payment methods', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_enabled_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'moksafowo_ecpay_credit'    => __( 'Credit card (pay in full)', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_credit_3'  => __( 'Credit card, 3 instalments', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_credit_6'  => __( 'Credit card, 6 instalments', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_credit_12' => __( 'Credit card, 12 instalments', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_credit_18' => __( 'Credit card, 18 instalments', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_credit_24' => __( 'Credit card, 24 instalments', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_atm'       => __( 'ATM virtual account', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_cvs'       => __( 'Convenience store code', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_barcode'   => __( 'Convenience store barcode', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_webatm'    => __( 'WebATM', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_applepay'  => __( 'Apple Pay', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_twqr'      => __( 'Mobile payment (TWQR Pay)', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_bnpl'      => __( 'Buy now, pay later', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_weixin'    => __( 'WeChat Pay', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_jkopay'    => __( 'JKOPAY', 'moksa-for-woocommerce' ),
					'moksafowo_ecpay_ipass'     => __( 'iPASS Money', 'moksa-for-woocommerce' ),
				],
				'desc'    => __( 'Only the ticked payment methods appear at checkout. This has no effect in combined display mode.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_display_setting',
			],
		];
	}
}
