<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Payuni\Gateways;

defined( 'ABSPATH' ) || exit;

final class Unified extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_unified';

	private const METHOD_MAP = array(
		'moksafowo_payuni_credit'     => 'Credit',
		'moksafowo_payuni_icash'      => 'ICash',
		'moksafowo_payuni_aftee'      => 'Aftee',
		'moksafowo_payuni_linepay'    => 'LinePay',
		'moksafowo_payuni_jkopay'     => 'JKoPay',
		'moksafowo_payuni_atm'        => 'ATM',
		'moksafowo_payuni_cvs'        => 'CVS',
		'moksafowo_payuni_unionpay'   => 'CreditUnionPay',
		'moksafowo_payuni_credit_red' => 'CreditRed',
		'moksafowo_payuni_applepay'   => 'ApplePay',
		'moksafowo_payuni_googlepay'  => 'GooglePay',
		'moksafowo_payuni_samsungpay' => 'SamsungPay',
	);

	public function __construct() {
		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi (single entry point)', 'moksa-for-woocommerce' );
		$this->method_description = __( 'Used when the checkout display is set to a single entry point. The customer is taken to the PAYUNi checkout to pick a payment method there.', 'moksa-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'inject_enabled_methods' ), 10, 2 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'             => array(
				'title'       => __( 'Enable', 'moksa-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable PAYUNi (single entry point)', 'moksa-for-woocommerce' ),
				'description' => __( 'Note: this only appears at checkout when the single entry point display is in use.', 'moksa-for-woocommerce' ),
				'default'     => 'yes',
			),
			'title'               => array(
				'title'   => __( 'Title', 'moksa-for-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'PAYUNi', 'moksa-for-woocommerce' ),
			),
			'description'         => array(
				'title'   => __( 'Description', 'moksa-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Every payment method in one place. After placing the order the customer is taken to the PAYUNi checkout.', 'moksa-for-woocommerce' ),
			),
			'allow_installment'   => array(
				'title'   => __( 'Allow instalments', 'moksa-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show credit card instalment options in the PAYUNi checkout', 'moksa-for-woocommerce' ),
				'default' => 'no',
			),
			'installment_periods' => array(
				'title'    => __( 'Available instalment plans', 'moksa-for-woocommerce' ),
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'css'      => 'width: 400px;',
				'options'  => array(
					3  => '3',
					6  => '6',
					9  => '9',
					12 => '12',
					18 => '18',
					24 => '24',
					30 => '30',
				),
				'desc'     => __( 'Applies when Allow instalments is ticked. Customers can choose from the plans you tick here on the PAYUNi payment page.', 'moksa-for-woocommerce' ),
				'desc_tip' => true,
			),
		);
	}

	public function is_available() {
		if ( 'single' !== get_option( 'moksafowo_payuni_display_mode', 'multi' ) ) {
			return false;
		}
		return parent::is_available();
	}

	public function inject_enabled_methods( $args, $order ) {
		foreach ( self::METHOD_MAP as $moksafowo_id => $pay_flag ) {
			$settings = (array) get_option( 'woocommerce_' . $moksafowo_id . '_settings', array() );
			if ( 'yes' === ( $settings['enabled'] ?? 'no' ) ) {
				$args[ $pay_flag ] = '1';
			}
		}

		// Installments — own toggle on Unified itself.
		if ( 'yes' === $this->get_option( 'allow_installment' ) ) {
			$periods = (array) $this->get_option( 'installment_periods', array() );
			$periods = array_filter( array_map( 'intval', $periods ) );
			if ( ! empty( $periods ) ) {
				$args['CreditInst'] = implode( ',', $periods );
			}
		}

		return $args;
	}
}
