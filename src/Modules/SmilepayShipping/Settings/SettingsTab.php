<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( 'General settings', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Once the shipping credentials are in place, enable the shipping zones under WooCommerce → Settings → Shipping.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_smilepay_shipping_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'When ticked, everything runs against the test environment and nothing is really dispatched. Untick it once you go live.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Store pickup account type', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_smilepay_shipping_cvs_service_type',
				'type'     => 'select',
				'default'  => 'C2C',
				'options'  => [
					'C2C' => __( 'Personal account — store-to-store, no monthly fee, and the most common choice', 'moksa-for-woocommerce' ),
					'B2C' => __( 'Bulk contract — requires a separate monthly agreement with SmilePay', 'moksa-for-woocommerce' ),
				],
				'desc'     => __( 'Choose whichever account type is activated in your SmilePay dashboard.', 'moksa-for-woocommerce' ),
				'desc_tip' => true,
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_shipping_section',
			],

			[
				'title' => __( 'Merchant credentials', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Find Dcvc, Rvg2c, Verify_key and the merchant number (SmseID) in the SmilePay dashboard under Member area → System identification data.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_creds_section',
			],
			[
				'title' => __( 'Dcvc — merchant code', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_dcvc',
				'type'  => 'text',
			],
			[
				'title' => __( 'Rvg2c — check code', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_rvg2c',
				'type'  => 'text',
			],
			[
				'title' => __( 'Verify_key — verification key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_verify_key',
				'type'  => 'text',
				'desc'  => __( 'Used to verify incoming shipping notifications so they cannot be forged.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'SmseID — merchant number', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_smseid',
				'type'  => 'text',
				'desc'  => __( 'Required by some shipping services.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_shipping_creds_section',
			],

			[
				'title' => __( 'Sender details', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Required for convenience store bulk shipping and T-Cat home delivery.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_section',
			],
			[
				'title' => __( 'Sender name', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_name',
				'type'  => 'text',
				'desc'  => __( '1 to 5 Chinese characters, or 1 to 10 Latin characters.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Sender mobile', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_phone',
				'type'  => 'text',
				'desc'  => __( '10 digits, starting with 09.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Sender email', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_email',
				'type'  => 'email',
			],
			[
				'title' => __( 'Sender address', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_smilepay_shipping_sender_address',
				'type'  => 'textarea',
				'desc'  => __( 'The sender address used for T-Cat home delivery.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_shipping_sender_section',
			],

			[
				'title' => __( 'Other', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_smilepay_shipping_misc_section',
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_smilepay_shipping_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Turn this on when investigating a problem shipment. The log lives under WooCommerce → Status → Logs.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_smilepay_shipping_misc_section',
			],
		];
	}
}
