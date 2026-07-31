<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EzpayInvoice\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( 'General settings', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Connects to ezPay e-invoicing. Sign up in the ezPay dashboard, create a store, then enter its credentials below.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ezpay_invoice_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ezpay_invoice_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'While this is ticked everything runs against the test environment and no invoice is really issued. Untick it once you go live.', 'moksa-for-woocommerce' ),
			],
			[
				'title'             => __( 'Order number prefix', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_ezpay_invoice_order_prefix',
				'type'              => 'text',
				'default'           => '',
				'desc'              => __( 'The prefix added to order numbers sent to ezPay. Letters and digits only, up to 5 characters. Leave blank for no prefix.', 'moksa-for-woocommerce' ),
				'desc_tip'          => true,
				'custom_attributes' => [
					'pattern'   => '[A-Za-z0-9]{0,5}',
					'maxlength' => 5,
				],
			],
			[
				'title'   => __( 'When to issue', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ezpay_invoice_issue_when',
				'type'    => 'select',
				'default' => 'paid',
				'options' => [
					'paid'      => __( 'As soon as payment completes', 'moksa-for-woocommerce' ),
					'completed' => __( 'When the order is completed, after shipping', 'moksa-for-woocommerce' ),
					'manual'    => __( 'Manual — you press Issue invoice on the order screen yourself', 'moksa-for-woocommerce' ),
				],
			],
			[
				'title'   => __( 'Void the invoice automatically when the order is refunded or cancelled', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ezpay_invoice_auto_cancel',
				'type'    => 'select',
				'default' => 'manual',
				'options' => [
					'manual'      => __( 'Manual — you open the order and press Void invoice yourself', 'moksa-for-woocommerce' ),
					'auto_cancel' => __( 'Automatic — voided two minutes after the order is cancelled, refunded or fails', 'moksa-for-woocommerce' ),
				],
				'desc'    => __( 'Set this to automatic and the invoice is voided whenever the order is cancelled, refunded or fails, so a forgotten void never turns into a tax problem.', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( 'Allow donations at checkout', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ezpay_invoice_allow_donate',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( 'Allow a tax ID at checkout, for triplicate business invoices', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ezpay_invoice_allow_b2b',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'         => __( 'Available personal carriers', 'moksa-for-woocommerce' ),
				'desc'          => __( 'ezPay platform member carrier', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_ezpay_invoice_channel_member',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			],
			[
				'desc'          => __( 'Mobile barcode', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_ezpay_invoice_channel_mobile',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'Citizen Digital Certificate', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_ezpay_invoice_channel_cert',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'Paper invoice', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_ezpay_invoice_channel_paper',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'end',
				'desc_tip'      => __( 'Tick the personal carriers offered at checkout and when issuing manually. Keep at least one; if none are ticked, paper is kept automatically.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Default personal carrier', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_ezpay_invoice_default_carrier',
				'type'     => 'select',
				'options'  => [
					'member' => __( 'ezPay platform member carrier', 'moksa-for-woocommerce' ),
					'mobile' => __( 'Mobile barcode', 'moksa-for-woocommerce' ),
					'cert'   => __( 'Citizen Digital Certificate', 'moksa-for-woocommerce' ),
					'paper'  => __( 'Paper invoice', 'moksa-for-woocommerce' ),
				],
				'default'  => 'member',
				'desc_tip' => __( 'The carrier selected by default at checkout and when issuing manually. It has to be one of the ones enabled above.', 'moksa-for-woocommerce' ),
			],
			[
				'title'       => __( 'Charities', 'moksa-for-woocommerce' ),
				'id'          => 'moksafowo_ezpay_invoice_donate_orgs',
				'type'        => 'textarea',
				'default'     => '',
				'css'         => 'min-height:90px;width:100%;',
				'placeholder' => '伊甸社會福利基金會|25885',
				'desc_tip'    => __( 'List the charities, one per line, as name|love code — for example Eden Social Welfare Foundation|25885. Once filled in, customers and admins choose from a dropdown showing the names. Leave it blank and customers type a love code themselves.', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ezpay_invoice_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Turn this on when investigating a problem invoice.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ezpay_invoice_section',
			],

			[
				'title' => __( 'Test credentials', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: ezPay sandbox URL */
					__( 'Apply for these at %s. ezPay has no shared public test account, so every merchant has their own test credentials.', 'moksa-for-woocommerce' ),
					'<a href="https://cinv.ezpay.com.tw" target="_blank">cinv.ezpay.com.tw</a>'
				),
				'id'    => 'moksafowo_ezpay_invoice_sandbox_section',
			],
			[
				'title' => __( 'Test merchant ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ezpay_invoice_sandbox_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Test hash key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ezpay_invoice_sandbox_hash_key',
				'type'  => 'text',
				'desc'  => __( '32 characters.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Test hash IV', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ezpay_invoice_sandbox_hash_iv',
				'type'  => 'text',
				'desc'  => __( '16 characters.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ezpay_invoice_sandbox_section',
			],

			[
				'title' => __( 'Live credentials', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Apply for these at inv.ezpay.com.tw. They only work once ezPay has approved and activated your account.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ezpay_invoice_prod_section',
			],
			[
				'title' => __( 'Live merchant ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ezpay_invoice_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ezpay_invoice_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash IV', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ezpay_invoice_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ezpay_invoice_prod_section',
			],
		];
	}
}
