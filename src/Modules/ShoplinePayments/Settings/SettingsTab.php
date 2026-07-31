<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		$callback_url = home_url( '/wc-api/moksafowo_shopline_payments' );

		return [
			[
				'title' => __( 'Merchant credentials', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Apply to Shopline Payments for a merchantId, apiKey and signKey. There is no public test account, so test credentials have to be requested separately. Keep the apiKey safe.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shopline_payments_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_shopline_payments_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'While this is ticked every transaction runs against the test environment and no money changes hands. Test and live credentials are separate. Untick it once you go live.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Test merchantId', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shopline_payments_sandbox_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Test apiKey', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shopline_payments_sandbox_api_key',
				'type'  => 'text',
			],
			[
				'title'    => __( 'Test signKey', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_shopline_payments_sandbox_sign_key',
				'type'     => 'text',
				'desc_tip' => __( 'Used to confirm that payment notifications are genuine.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Test platformId', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_shopline_payments_sandbox_platform_id',
				'type'     => 'text',
				'desc_tip' => __( 'Only platform integrators need this. Leave it blank otherwise.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Live merchantId', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shopline_payments_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live apiKey', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_shopline_payments_api_key',
				'type'  => 'text',
			],
			[
				'title'    => __( 'Live signKey', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_shopline_payments_sign_key',
				'type'     => 'text',
				'desc_tip' => __( 'Used to confirm that payment notifications are genuine.', 'moksa-for-woocommerce' ),
			],
			[
				'title'    => __( 'Live platformId', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_shopline_payments_platform_id',
				'type'     => 'text',
				'desc_tip' => __( 'Only platform integrators need this. Leave it blank otherwise.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shopline_payments_section',
			],

			[
				'title' => __( 'Payment notifications', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				/* translators: %s: callback URL */
				'desc'  => sprintf(
					/* translators: %s: notification URL */
					__( 'Shopline Payments has to enable payment notifications by hand. Send them the address below, once for the test environment and once for the live one:<br><code>%s</code><br>Until that is done, orders will not update automatically when a payment completes.', 'moksa-for-woocommerce' ),
					esc_url( $callback_url )
				),
				'id'    => 'moksafowo_shopline_payments_webhook_section',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shopline_payments_webhook_section',
			],

			[
				'title' => __( 'Payment settings', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_shopline_payments_misc_section',
			],
			[
				'title'   => __( 'Allowed payment methods', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_shopline_payments_payment_methods',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'width: 400px;',
				'options' => [
					'CreditCard'     => __( 'Credit card', 'moksa-for-woocommerce' ),
					'VirtualAccount' => __( 'Virtual account (ATM)', 'moksa-for-woocommerce' ),
					'ApplePay'       => __( 'Apple Pay', 'moksa-for-woocommerce' ),
					'LinePay'        => __( 'LINE Pay', 'moksa-for-woocommerce' ),
					'JKOPay'         => __( 'JKOPAY', 'moksa-for-woocommerce' ),
					'ChaileaseBNPL'  => __( 'Zingala instalments', 'moksa-for-woocommerce' ),
				],
				'desc'    => __( 'Limits which methods are offered on the payment page. Leave blank for no limit. What actually appears depends on what Shopline Payments has activated for you.', 'moksa-for-woocommerce' ),
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_shopline_payments_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Turn this on when investigating a problem order. The log lives under WooCommerce → Status → Logs.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_shopline_payments_misc_section',
			],
		];
	}
}
