<?php

namespace Moksafowo\Modules\PayuniShipping\Settings;

defined( 'ABSPATH' ) || exit;

class SettingsTab extends \WC_Settings_Page {

	public function __construct() {

		$this->id    = 'moksafowo_payuni';
		$this->label = __( 'PAYUNi', 'moksa-for-woocommerce' );

		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );

		add_action( 'admin_init', array( $this, 'moksafowo_payuni_shipping_redirect_default_tab' ) );

		add_filter( 'moksafowo_get_sections_' . $this->id, array( $this, 'moksafowo_payuni_shipping_sections' ), 11, 1 );

		parent::__construct();
	}

	public function moksafowo_payuni_shipping_sections( $sections ) {

		unset( $sections[''] );
		if ( is_array( $sections ) && ! array_key_exists( 'shipping', $sections ) ) {
			$sections['shipping'] = __( 'Shipping settings', 'moksa-for-woocommerce' );
		}
		return $sections;
	}

	public function get_sections() {

		if ( 'yes' !== get_option( 'moksafowo_payuni_enabled', 'no' ) ) {
			$sections = array(
				'shipping' => __( 'Shipping settings', 'moksa-for-woocommerce' ),
			);
			return apply_filters( 'moksafowo_get_sections_' . $this->id, $sections );
		}
		return array();
	}

	public function get_settings_for_shipping_section() {
		$settings = apply_filters(
			'moksafowo_payuni_shipping_settings',
			array(
				array(
					'title' => __( 'General settings', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'id'    => 'shipping_general_setting',
				),
				array(
					'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => sprintf(
						/* translators: %s = view logs link */
						__( 'Turn this on when investigating a problem shipment. The log lives under WooCommerce → Status → Logs. %s', 'moksa-for-woocommerce' ),
						$this->get_log_link()
					),
					'id'      => 'moksafowo_payuni_shipping_debug_log_enabled',
				),
				array(
					'title'    => __( 'Store picker layout', 'moksa-for-woocommerce' ),
					'type'     => 'select',
					'desc_tip' => __( 'Pick to suit your theme — one column for narrow checkout layouts, two columns for wider ones.', 'moksa-for-woocommerce' ),
					'options'  => array(
						'single_column' => __( 'One column, label above the value (recommended)', 'moksa-for-woocommerce' ),
						'two_column'    => __( 'Two columns, label on the left and value on the right', 'moksa-for-woocommerce' ),
					),
					'default'  => 'single_column',
					'id'       => 'moksafowo_payuni_shipping_cvs_selector_layout',
				),
				array(
					'title'   => __( 'Hide the billing address fields for store pickup', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => __( 'When the customer chooses convenience store pickup, hide the city, district, postcode and address fields at checkout, since the store details replace them.', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_payuni_shipping_hide_billing_address_fields',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'shipping_general_setting',
				),

				array(
					'title' => __( 'Sender details', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'desc'  => __( 'The sender details used when a shipment is created.', 'moksa-for-woocommerce' ),
					'id'    => 'moksafowo_payuni_shipping_store_settings',
				),
				array(
					'title' => __( 'Name', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_shipping_sender_name',
				),
				array(
					'title' => __( 'Phone', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_shipping_sender_phone',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'shipping_store_setting',
				),

				array(
					'title' => __( 'Update the order status from tracking events', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'desc'  => __( 'Move the order to the chosen status whenever the shipment progresses. Leave blank to make no change.', 'moksa-for-woocommerce' ),
					'id'    => 'moksafowo_payuni_shipping_shipping_settings',
				),
				array(
					'title'   => __( '7-ELEVEN merchant shipping: accepted at the distribution center', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'options' => self::moksafowo_payuni_get_order_status(),
					'id'      => 'moksafowo_payuni_shipping_order_status_at_logistic_center',
				),
				array(
					'title'   => __( '7-ELEVEN personal shipping: dropped off at the store by the seller', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'options' => self::moksafowo_payuni_get_order_status(),
					'id'      => 'moksafowo_payuni_shipping_order_status_at_sender_cvs',
				),
				array(
					'title'   => __( 'In transit', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'options' => self::moksafowo_payuni_get_order_status(),
					'id'      => 'moksafowo_payuni_shipping_order_status_delivering',
				),
				array(
					'title'   => __( 'Arrived at the pickup store', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'options' => self::moksafowo_payuni_get_order_status(),
					'id'      => 'moksafowo_payuni_shipping_order_status_at_receiver_cvs',
				),
				array(
					'title'   => __( 'Collected', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'options' => self::moksafowo_payuni_get_order_status(),
					'id'      => 'moksafowo_payuni_shipping_order_status_pickuped',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'shipping_order_setting',
				),

				array(
					'title' => __( 'T-Cat home delivery', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'id'    => 'shipping_TCat_setting',
				),
				array(
					'title'   => __( 'Delivery window', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'options' => array(
						'01' => __( 'Before 1 pm', 'moksa-for-woocommerce' ),
						'02' => __( '14:00 - 18:00', 'moksa-for-woocommerce' ),
						'04' => __( 'No preference', 'moksa-for-woocommerce' ),
					),
					'default' => '04',
					'id'      => 'moksafowo_payuni_shipping_tcat_delivery_time',
				),
				array(
					'title'   => __( 'Expected dispatch date, in days after the label is printed', 'moksa-for-woocommerce' ),
					'type'    => 'number',
					'default' => 1,
					'desc'    => __( 'The default of 1 means the parcel ships the day after the label is printed.', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_payuni_shipping_tcat_estimate_shipping_date',
				),
				array(
					'title'   => __( 'Convenience store label format', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'options' => array(
						'1' => __( 'A4', 'moksa-for-woocommerce' ),
						'2' => __( 'Portrait (B2C only)', 'moksa-for-woocommerce' ),
					),
					'id'      => 'moksafowo_payuni_shipping_cvs_label_mode',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'shipping_TCat_setting',
				),

				array(
					'title' => __( 'Merchant credentials', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'desc'  => __( 'Copy these from the PAYUNi dashboard under Member area → Integration settings. They are the same credentials the payment module uses.', 'moksa-for-woocommerce' ),
					'id'    => 'moksafowo_payuni_shipping_api_settings',
				),
				array(
					'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'yes',
					'desc'    => __( 'For use before going live. While this is ticked every shipment runs against the test environment and nothing is really dispatched. Untick it once you go live.', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_payuni_shipping_testmode_enabled',
				),
				array(
					'title' => __( 'Test merchant ID', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_merchant_id_test',
				),
				array(
					'title' => __( 'Test hash key', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_hashkey_test',
				),
				array(
					'title' => __( 'Test hash IV', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_hashiv_test',
				),
				array(
					'title' => __( 'Live merchant ID', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_merchant_id',
				),
				array(
					'title' => __( 'Live hash key', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_hashkey',
				),
				array(
					'title' => __( 'Live hash IV', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_hashiv',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'moksafowo_payuni_shipping_api_settings',
				),
			)
		);

		return $settings;
	}

	private static function moksafowo_payuni_get_order_status() {
		$order_statuses = array(
			'' => __( 'No change', 'moksa-for-woocommerce' ),
		);

		foreach ( wc_get_order_statuses() as $slug => $name ) {
			if ( $slug === 'wc-cancelled' || $slug === 'wc-refunded' || $slug === 'wc-failed' ) {
				continue;
			}
			$order_statuses[ str_replace( 'wc-', '', $slug ) ] = $name;
		}

		return $order_statuses;
	}

	public function moksafowo_payuni_shipping_redirect_default_tab() {

		global $pagenow;

		if ( 'admin.php' !== $pagenow ) {
			return;
		}

		if ( 'yes' === get_option( 'moksafowo_payuni_enabled', 'no' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect dispatch on WC settings tab.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect dispatch on WC settings tab.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( 'wc-settings' === $page && 'payuni' === $tab ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect dispatch on WC settings tab.
			if ( empty( $_GET['section'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=payuni&section=shipping' ) );
				exit;
			}
		}
	}

	public function output() {

		global $current_section;

		if ( 'shipping' !== $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::output_fields( $settings );
	}

	public function save() {

		global $current_section;

		if ( 'shipping' !== $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::save_fields( $settings );
	}

	protected function get_log_link() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . __( 'View logs', 'moksa-for-woocommerce' ) . '</a>';
	}
}
