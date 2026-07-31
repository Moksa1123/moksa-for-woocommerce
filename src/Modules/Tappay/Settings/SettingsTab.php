<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( 'Merchant credentials', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Apply to TapPay for an app ID, app key, partner key and merchant ID, then add this site\'s address to the allowed domains in the TapPay dashboard.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_tappay_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_tappay_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'While this is ticked everything runs against the TapPay test environment and no money changes hands. Leave the test fields below blank and the official TapPay public test account is used, where card 4242 4242 4242 4242 with CVC 123 works. Untick it and fill in the live credentials before going live.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Test app ID', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_tappay_sandbox_app_id',
				'type'     => 'text',
				'desc_tip' => __( 'Leave blank to use the TapPay public test app ID.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Test app key', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_tappay_sandbox_app_key',
				'type'     => 'text',
				'desc_tip' => __( 'Leave blank to use the public test app key.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Test partner key', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_tappay_sandbox_partner_key',
				'type'     => 'text',
				'desc_tip' => __( 'Keep this safe. Leave blank to use the public test partner key.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Test merchant ID', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_tappay_sandbox_merchant_id',
				'type'     => 'text',
				'desc_tip' => __( 'Leave blank to use the TapPay public test merchant ID.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Test webhook signing key', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_tappay_sandbox_notify_secret',
				'type'     => 'text',
				'desc_tip' => __( 'The notification signing key set in the TapPay dashboard, used to verify payment notifications. Leave blank to use the partner key.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Live app ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_tappay_app_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live app key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_tappay_app_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live partner key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_tappay_partner_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live merchant ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_tappay_merchant_id',
				'type'  => 'text',
			],
			[
				'title'    => __( 'Live webhook signing key', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_tappay_notify_secret',
				'type'     => 'text',
				'desc_tip' => __( 'The notification signing key set in the TapPay dashboard. Leave blank to use the partner key.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_tappay_section',
			],

			[
				'title' => __( 'Payment settings', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_tappay_misc_section',
			],
			[
				'title'   => __( 'Enable 3-D Secure', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_tappay_3ds_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Worth turning on: it shifts liability and cuts down on chargeback disputes. Customers are sent to their bank\'s verification page and returned here once it is done.', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_tappay_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Turn this on when investigating a problem order. The log lives under WooCommerce → Status → Logs.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_tappay_misc_section',
			],
		];
	}
}
