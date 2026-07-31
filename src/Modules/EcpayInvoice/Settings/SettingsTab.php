<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayInvoice\Settings;

use Moksafowo\Modules\EcpayInvoice\Api\Helper;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( 'Merchant credentials', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Copy these from the ECPay invoicing dashboard under Merchant area → System identification data. An invoicing account is <strong>applied for separately</strong> from payments and shipping.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_invoice_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_invoice_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'For use before going live. While this is ticked every invoice runs against the test environment and nothing is really issued. Untick it once you go live.', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( 'Test merchant ID', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_invoice_sandbox_merchant_id',
				'type'    => 'text',
				'default' => Helper::SANDBOX_MERCHANT_ID,
			],
			[
				'title'   => __( 'Test hash key', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_invoice_sandbox_hash_key',
				'type'    => 'text',
				'default' => Helper::SANDBOX_HASH_KEY,
			],
			[
				'title'   => __( 'Test hash IV', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_invoice_sandbox_hash_iv',
				'type'    => 'text',
				'default' => Helper::SANDBOX_HASH_IV,
			],
			[
				'title' => __( 'Live merchant ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_invoice_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_invoice_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash IV', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_invoice_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_invoice_section',
			],

			[
				'title' => __( 'Issuing rules', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_ecpay_invoice_behavior_section',
			],
			[
				'title'             => __( 'Invoice number prefix', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_ecpay_invoice_prefix',
				'type'              => 'text',
				'default'           => '',
				'desc_tip'          => __( 'Up to 5 letters or digits. If several stores share one ECPay invoicing account, a prefix keeps their invoice numbers apart.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'pattern'   => '[a-zA-Z0-9]{0,5}',
					'maxlength' => 5,
				],
			],
			[
				'title'   => __( 'When to issue', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_invoice_issue_when',
				'type'    => 'select',
				'options' => [
					'paid'      => __( 'As soon as payment completes (recommended)', 'moksa-for-woocommerce' ),
					'completed' => __( 'When the order is completed, after shipping', 'moksa-for-woocommerce' ),
					'manual'    => __( 'Only manually, using the button on the order screen', 'moksa-for-woocommerce' ),
				],
				'default' => 'paid',
			],
			[
				'title'             => __( 'Delay before issuing (days)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_ecpay_invoice_delay_days',
				'type'              => 'number',
				'default'           => 0,
				'desc_tip'          => __( 'How many days to wait after the issuing condition is met. The default of 0 issues right away. A common setup is to wait a few days after the order completes, so cancellations and refunds do not need voiding.', 'moksa-for-woocommerce' ),
				'custom_attributes' => [
					'min'  => 0,
					'max'  => 30,
					'step' => 1,
				],
			],
			[
				'title'   => __( 'Void the invoice automatically when the order is refunded or cancelled', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_invoice_auto_cancel',
				'type'    => 'select',
				'default' => 'manual',
				'options' => [
					'manual'      => __( 'Manual — you open the order and press Void invoice yourself', 'moksa-for-woocommerce' ),
					'auto_cancel' => __( 'Automatic — voided two minutes after the order is cancelled, refunded or fails', 'moksa-for-woocommerce' ),
				],
				'desc'    => __( 'Set this to automatic and the invoice is voided whenever the order is cancelled, refunded or fails, so a forgotten void never turns into a tax problem.', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( 'Verify carriers and love codes', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_invoice_carrier_api_check',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Check at checkout that the mobile barcode or love code the customer entered really exists, so a made-up one does not break the invoice later.', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( 'Allow donations at checkout', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_invoice_allow_donate',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Offer the donation option, where the customer enters a 3 to 7 digit donation code.', 'moksa-for-woocommerce' ),
			],
			[
				'title'       => __( 'Charities', 'moksa-for-woocommerce' ),
				'id'          => 'moksafowo_ecpay_invoice_donate_orgs',
				'type'        => 'textarea',
				'default'     => '',
				'css'         => 'min-height:90px;width:100%;',
				'placeholder' => '伊甸社會福利基金會|25885',
				'desc_tip'    => __( 'List the charities, one per line, as name|love code — for example Eden Social Welfare Foundation|25885. Once filled in, customers and admins choose from a dropdown showing the names. Leave it blank and customers type a love code themselves.', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( 'Allow a tax ID at checkout, for triplicate business invoices', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_invoice_allow_b2b',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Offer a tax ID field so businesses can receive a triplicate invoice.', 'moksa-for-woocommerce' ),
			],
			[
				'title'         => __( 'Available personal carriers', 'moksa-for-woocommerce' ),
				'desc'          => __( 'Member carrier (stored in the cloud, nothing to enter)', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_ecpay_invoice_channel_member',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			],
			[
				'desc'          => __( 'Mobile barcode', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_ecpay_invoice_channel_mobile',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'Citizen Digital Certificate', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_ecpay_invoice_channel_cert',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'Paper', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_ecpay_invoice_channel_paper',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'end',
				'desc_tip'      => __( 'Tick the personal carriers offered at checkout and when issuing manually. Keep at least one; if none are ticked, paper is kept automatically.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Default personal carrier', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_ecpay_invoice_default_carrier',
				'type'     => 'select',
				'options'  => [
					'member' => __( 'Member carrier (stored in the cloud, nothing to enter)', 'moksa-for-woocommerce' ),
					'mobile' => __( 'Mobile barcode (the customer enters 8 characters)', 'moksa-for-woocommerce' ),
					'cert'   => __( 'Citizen Digital Certificate (the customer enters 16 characters)', 'moksa-for-woocommerce' ),
					'paper'  => __( 'Paper (no carrier)', 'moksa-for-woocommerce' ),
				],
				'default'  => 'member',
				'desc_tip' => __( 'The carrier selected by default at checkout and when issuing manually. It has to be one of the ones enabled above.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_invoice_behavior_section',
			],

			[
				'title' => __( 'Other', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_ecpay_invoice_debug_section',
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_invoice_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Turn this on when investigating a problem invoice.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_invoice_debug_section',
			],
		];
	}
}
