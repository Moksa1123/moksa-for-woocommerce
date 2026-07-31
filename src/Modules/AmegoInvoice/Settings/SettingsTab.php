<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\AmegoInvoice\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( 'General settings', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Connects to Amego e-invoicing. A public test account is built in, so ticking test mode lets you try issuing right away. Fill in the live credentials below before going live.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_amego_invoice_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Uses the built-in public test credentials, unless you enter your own below.', 'moksa-for-woocommerce' ),
			],
			[
				'title'             => __( 'Order number prefix', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_amego_invoice_order_prefix',
				'type'              => 'text',
				'default'           => '',
				'desc'              => __( 'The prefix added to order numbers sent to Amego. Letters and digits only, up to 5 characters. Leave blank for no prefix.', 'moksa-for-woocommerce' ),
				'desc_tip'          => true,
				'custom_attributes' => [
					'pattern'   => '[A-Za-z0-9]{0,5}',
					'maxlength' => 5,
				],
			],
			[
				'title'   => __( 'When to issue', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_amego_invoice_issue_when',
				'type'    => 'select',
				'default' => 'paid',
				'options' => [
					'paid'      => __( 'As soon as payment completes', 'moksa-for-woocommerce' ),
					'completed' => __( 'When the order is completed', 'moksa-for-woocommerce' ),
					'manual'    => __( 'Manual — you press the button on the order screen', 'moksa-for-woocommerce' ),
				],
			],
			[
				'title'   => __( 'Void the invoice automatically when the order is refunded or cancelled', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_amego_invoice_auto_cancel',
				'type'    => 'select',
				'default' => 'manual',
				'options' => [
					'manual'      => __( 'Manual', 'moksa-for-woocommerce' ),
					'auto_cancel' => __( 'Automatic — voided two minutes after the order is cancelled, refunded or fails', 'moksa-for-woocommerce' ),
				],
			],
			[
				'title'   => __( 'Allow donations at checkout', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_amego_invoice_allow_donate',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( 'Allow a tax ID at checkout, for triplicate business invoices', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_amego_invoice_allow_b2b',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'         => __( 'Available personal carriers', 'moksa-for-woocommerce' ),
				'desc'          => __( 'Amego member carrier', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_amego_invoice_channel_member',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			],
			[
				'desc'          => __( 'Mobile barcode', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_amego_invoice_channel_mobile',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'Citizen Digital Certificate', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_amego_invoice_channel_cert',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			],
			[
				'desc'          => __( 'Paper invoice', 'moksa-for-woocommerce' ),
				'id'            => 'moksafowo_amego_invoice_channel_paper',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'end',
				'desc_tip'      => __( 'Tick the personal carriers offered at checkout and when issuing manually. Keep at least one; if none are ticked, paper is kept automatically.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Default personal carrier', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_amego_invoice_default_carrier',
				'type'     => 'select',
				'options'  => [
					'member' => __( 'Amego member carrier', 'moksa-for-woocommerce' ),
					'mobile' => __( 'Mobile barcode', 'moksa-for-woocommerce' ),
					'cert'   => __( 'Citizen Digital Certificate', 'moksa-for-woocommerce' ),
					'paper'  => __( 'Paper invoice', 'moksa-for-woocommerce' ),
				],
				'default'  => 'member',
				'desc_tip' => __( 'The carrier selected by default at checkout and when issuing manually. It has to be one of the ones enabled above.', 'moksa-for-woocommerce' ),
			],
			[
				'title'       => __( 'Charities', 'moksa-for-woocommerce' ),
				'id'          => 'moksafowo_amego_invoice_donate_orgs',
				'type'        => 'textarea',
				'default'     => '',
				'css'         => 'min-height:90px;width:100%;',
				'placeholder' => '伊甸社會福利基金會|25885',
				'desc_tip'    => __( 'List the charities, one per line, as name|love code — for example Eden Social Welfare Foundation|25885. Once filled in, customers and admins choose from a dropdown showing the names. Leave it blank and customers type a love code themselves.', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_amego_invoice_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_amego_invoice_section',
			],

			[
				'title' => __( 'Test credentials (optional)', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Leave blank to use the built-in public test account. Enter your own here to use your own test environment.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_sandbox_section',
			],
			[
				'title' => __( 'Test tax ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_sandbox_invoice_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Test app key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_sandbox_app_key',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_amego_invoice_sandbox_section',
			],

			[
				'title' => __( 'Live credentials', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Get the app key for your company tax ID from the invoice.amego.tw dashboard.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_prod_section',
			],
			[
				'title' => __( 'Live tax ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_invoice_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live app key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_amego_invoice_app_key',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_amego_invoice_prod_section',
			],
		];
	}
}
