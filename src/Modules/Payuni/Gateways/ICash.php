<?php
namespace Moksafowo\Modules\Payuni\Gateways;

defined( 'ABSPATH' ) || exit;

class ICash extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_icash';

	public function __construct() {
		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi iCash Pay', 'moksa-for-woocommerce' );
		$this->method_description = __( 'Pay with iCash Pay. The customer is redirected to the PAYUNi payment page.', 'moksa-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'moksafowo_payuni_payment_icash_transaction_arrgs' ), 10, 2 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'moksa-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PAYUNi iCash Pay', 'moksa-for-woocommerce' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'   => __( 'Title', 'moksa-for-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'iCash Pay', 'moksa-for-woocommerce' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'moksa-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => '',
			),
		);
	}

	public function moksafowo_payuni_payment_icash_transaction_arrgs( $args, $order ) {
		return array_merge( $args, array( 'ICash' => '1' ) );
	}
}
