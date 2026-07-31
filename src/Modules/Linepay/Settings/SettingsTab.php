<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Linepay\Settings;

use WC_Admin_Settings;
use WC_Settings_Page;

defined( 'ABSPATH' ) || exit;

class SettingsTab extends WC_Settings_Page {

	public function __construct() {

		$this->id    = 'moksafowo-linepay';
		$this->label = __( 'LINE Pay', 'moksa-for-woocommerce' );

		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );
		add_action( 'woocommerce_sections_' . $this->id, array( $this, 'output_sections' ) );

		parent::__construct();
	}

	public function get_sections() {

		$sections = array(
			'' => __( 'Payment settings', 'moksa-for-woocommerce' ),
		);

		return apply_filters( 'moksafowo_get_sections_' . $this->id, $sections );
	}

	public function get_settings( $current_section = '' ) {

		$settings = apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- moksafowo_linepay_ is wpbrewer fork BC prefix per CLAUDE.md fork-then-patch.
			'moksafowo_linepay_payment_settings',
			array(
				array(
					'title' => __( 'General', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'id'    => 'moksafowo_linepay_general_setting',
				),
				array(
					'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => sprintf(
						/* translators: %s = view logs link */
						__( 'Turn this on when investigating a problem order. The log lives under WooCommerce → Status → Logs. %s', 'moksa-for-woocommerce' ),
						$this->get_log_link()
					),
					'id'      => 'moksafowo_linepay_debug_log_enabled',
				),
				array(
					'title'   => __( 'Show the LINE Pay logo at checkout', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => __( 'Show the official LINE Pay logo next to the payment method at checkout.', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_linepay_display_logo_enabled',
				),
				array(
					'title'   => __( 'Status after a failed payment', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'options' => wc_get_order_statuses(),
					'desc'    => __( 'The order moves to this status when a LINE Pay payment fails.', 'moksa-for-woocommerce' ),
					'default' => 'wc-failed',
					'id'      => 'moksafowo_linepay_payment_fail_order_status',
				),
				array(
					'title'   => __( 'Add detailed status to order notes', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => __( 'Write every status LINE Pay returns into the order notes. Handy while testing, but worth turning off in production to keep the notes readable.', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_linepay_detail_status_note_enabled',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'moksafowo_linepay_general_setting',
				),
				array(
					'title' => __( 'Merchant credentials', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'desc'  => __( 'Copy these from the LINE Pay merchant dashboard under Admin center → Link key management.', 'moksa-for-woocommerce' ),
					'id'    => 'moksafowo_linepay_api_settings',
				),
				array(
					'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => __( 'While this is ticked every transaction runs against the LINE Pay test environment and no money changes hands. Untick it once you go live.', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_linepay_sandboxmode_enabled',
				),
				array(
					'title'   => __( 'Test channel ID', 'moksa-for-woocommerce' ),
					'type'    => 'text',
					'default' => '',
					'id'      => 'moksafowo_linepay_sandbox_channel_id',
				),
				array(
					'title'   => __( 'Test channel secret', 'moksa-for-woocommerce' ),
					'type'    => 'text',
					'default' => '',
					'id'      => 'moksafowo_linepay_sandbox_channel_secret',
				),
				array(
					'title'   => __( 'Live channel ID', 'moksa-for-woocommerce' ),
					'type'    => 'text',
					'default' => '',
					'id'      => 'moksafowo_linepay_channel_id',
				),
				array(
					'title'   => __( 'Live channel secret', 'moksa-for-woocommerce' ),
					'type'    => 'text',
					'default' => '',
					'id'      => 'moksafowo_linepay_channel_secret',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'moksafowo_linepay_api_settings',
				),
			)
		);

		return apply_filters( 'moksafowo_get_settings_' . $this->id, $settings, $current_section );
	}

	public function output(): void {
		global $current_section;
		$settings = $this->get_settings( $current_section );
		WC_Admin_Settings::output_fields( $settings );
	}

	public function save(): void {
		global $current_section;
		$settings = $this->get_settings( $current_section );
		WC_Admin_Settings::save_fields( $settings );
	}

	protected function get_log_link(): string {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . __( 'View logs', 'moksa-for-woocommerce' ) . '</a>';
	}
}
