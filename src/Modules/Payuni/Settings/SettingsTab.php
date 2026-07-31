<?php


namespace Moksafowo\Modules\Payuni\Settings;

use Moksafowo\Modules\Payuni\Gateways\CreditInstallment12;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment18;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment24;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment3;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment30;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment6;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment9;

defined( 'ABSPATH' ) || exit;


class SettingsTab extends \WC_Settings_Page {


	public function __construct() {

		$this->id    = 'moksafowo_payuni';
		$this->label = __( 'PAYUNi', 'moksa-for-woocommerce' );

		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );

		add_action( 'admin_init', array( $this, 'moksafowo_payuni_redirect_default_tab' ) );

		add_filter( 'woocommerce_get_sections_' . $this->id, array( $this, 'moksafowo_payuni_payment_sections' ), 10, 1 );

		parent::__construct();
	}


	public function moksafowo_payuni_payment_sections( $sections ) {

		unset( $sections[''] );
		if ( is_array( $sections ) && ! array_key_exists( 'payment', $sections ) ) {
			$sections['payment'] = __( 'Payment Settings', 'moksa-for-woocommerce' );
		}
		return $sections;
	}


	public function get_settings_for_payment_section() {

		$settings = apply_filters(
			'moksafowo_payuni_payment_settings',
			array(
				array(
					'title' => __( 'General', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'id'    => 'payment_general_setting',
				),
				array(
					'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'yes',
					'desc'    => sprintf(
						/* translators: %s = view logs link */
						__( 'Turn this on when investigating a problem order. The log lives under WooCommerce → Status → Logs. %s', 'moksa-for-woocommerce' ),
						$this->get_log_link()
					),
					'id'      => 'moksafowo_payuni_payment_debug_log_enabled',
				),
				array(
					'title'   => __( 'Checkout language', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'css'     => 'width: 200px;',
					'options' => array(
						'zh-tw' => __( 'Traditional Chinese', 'moksa-for-woocommerce' ),
						'en'    => __( 'English', 'moksa-for-woocommerce' ),
					),
					'default' => 'zh-tw',
					'desc'    => __( 'The language the PAYUNi checkout is shown in.', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_payuni_payment_language',
				),
				array(
					'title'   => __( 'E-invoice', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => __( 'Issue e-invoices through PAYUNi. You need to apply for and enable invoicing in the PAYUNi dashboard first.', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_payuni_payment_einvoice_enabled',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'payment_general_setting',
				),

				array(
					'title' => __( 'How the checkout looks', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'desc'  => __( 'Choose whether the customer sees one button that goes to PAYUNi to pick a method, or a separate button for each payment method.', 'moksa-for-woocommerce' ),
					'id'    => 'moksafowo_payuni_display_setting',
				),
				array(
					'title'   => __( 'Display style', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'css'     => 'width: 400px;',
					'options' => array(
						'multi'  => __( 'Separate — one button per payment method (recommended)', 'moksa-for-woocommerce' ),
						'single' => __( 'Combined — a single PAYUNi button that leads to the checkout page', 'moksa-for-woocommerce' ),
					),
					'default' => 'multi',
					'id'      => 'moksafowo_payuni_display_mode',
				),
				array(
					'title'   => __( 'Enabled payment methods', 'moksa-for-woocommerce' ),
					'type'    => 'multiselect',
					'class'   => 'wc-enhanced-select',
					'css'     => 'width: 400px;',
					'options' => array(
						'moksafowo_payuni_credit'     => __( 'Credit card', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_atm'        => __( 'ATM virtual account', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_cvs'        => __( 'Convenience store code', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_aftee'      => __( 'AFTEE pay later', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_applepay'   => __( 'Apple Pay', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_googlepay'  => __( 'Google Pay', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_samsungpay' => __( 'Samsung Pay', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_linepay'    => __( 'LINE Pay', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_unionpay'   => __( 'UnionPay', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_icash'      => __( 'iCash', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_jkopay'     => __( 'JKOPAY', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_credit_red' => __( 'Credit card reward points', 'moksa-for-woocommerce' ),
					),
					'desc'    => __( 'Only the ticked payment methods appear at checkout. This has no effect in combined display mode.', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_payuni_enabled_methods',
				),
				array(
					'title'   => __( 'Enabled instalment plans', 'moksa-for-woocommerce' ),
					'type'    => 'multiselect',
					'class'   => 'wc-enhanced-select',
					'css'     => 'width: 400px;',
					'options' => array(
						CreditInstallment3::GATEWAY_ID  => __( '3 instalments', 'moksa-for-woocommerce' ),
						CreditInstallment6::GATEWAY_ID  => __( '6 instalments', 'moksa-for-woocommerce' ),
						CreditInstallment9::GATEWAY_ID  => __( '9 instalments', 'moksa-for-woocommerce' ),
						CreditInstallment12::GATEWAY_ID => __( '12 instalments', 'moksa-for-woocommerce' ),
						CreditInstallment18::GATEWAY_ID => __( '18 instalments', 'moksa-for-woocommerce' ),
						CreditInstallment24::GATEWAY_ID => __( '24 instalments', 'moksa-for-woocommerce' ),
						CreditInstallment30::GATEWAY_ID => __( '30 instalments', 'moksa-for-woocommerce' ),
					),
					'desc'    => sprintf(
						/* translators: %s = link to WC payment settings */
						__( 'Each plan you tick becomes its own payment method, which you still need to enable individually in the payment method settings. %s', 'moksa-for-woocommerce' ),
						$this->get_woo_payment_settings_url()
					),
					'id'      => 'moksafowo_payuni_payment_installment_number_of_payments',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'moksafowo_payuni_display_setting',
				),

				array(
					'title' => __( 'Merchant credentials', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'desc'  => __( 'Copy these from the PAYUNi dashboard under Member area → Integration settings.', 'moksa-for-woocommerce' ),
					'id'    => 'moksafowo_payuni_payment_api_settings',
				),
				array(
					'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'yes',
					'desc'    => __( 'For use before going live. While this is ticked every transaction runs against the test environment and no money changes hands. Untick it once you go live.', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_payuni_payment_testmode_enabled',
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
					'id'   => 'moksafowo_payuni_payment_api_settings',
				),
			)
		);

		return $settings;
	}


	public function moksafowo_payuni_redirect_default_tab() {

		global $pagenow;

		if ( 'admin.php' !== $pagenow ) {
			return;
		}

     // phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page    = ( array_key_exists( 'page', $_GET ) ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = ( array_key_exists( 'tab', $_GET ) ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = ( array_key_exists( 'section', $_GET ) ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		if ( 'wc-settings' === $page && 'payuni' === $tab ) {

			if ( empty( $section ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=payuni&section=payment' ) );
				exit;
			}
		}
	}


	public function output() {
		global $current_section;

		if ( 'payment' !== $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::output_fields( $settings );
	}


	public function save() {
		global $current_section;

		if ( 'payment' !== $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::save_fields( $settings );
	}


	private function get_log_link() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '" target="_blank">' . __( 'View logs', 'moksa-for-woocommerce' ) . '</a>';
	}


	private function get_woo_payment_settings_url() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) . '" target="_blank">' . __( 'Go to Payment Settings', 'moksa-for-woocommerce' ) . '</a>';
	}
}
