<?php
namespace MoksaWeb\Mowc\Modules\Payuni\Gateways;

defined( 'ABSPATH' ) || exit;

class CreditRed extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_credit_red';

	public function __construct() {
		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi 信用卡紅利折抵', 'mo-ectools' );
		$this->method_description = __( 'PAYUNi UPP — 信用卡紅利點數折抵', 'mo-ectools' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'moksafowo_payuni_payment_credit_red_transaction_arrgs' ), 10, 2 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( '啟用', 'mo-ectools' ),
				'type'    => 'checkbox',
				'label'   => __( '啟用 PAYUNi 信用卡紅利折抵', 'mo-ectools' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'   => __( '結帳顯示名稱', 'mo-ectools' ),
				'type'    => 'text',
				'default' => __( '信用卡紅利折抵', 'mo-ectools' ),
			),
			'description' => array(
				'title'   => __( '結帳顯示說明', 'mo-ectools' ),
				'type'    => 'textarea',
				'default' => '',
			),
		);
	}

	public function moksafowo_payuni_payment_credit_red_transaction_arrgs( $args, $order ) {
		return array_merge( $args, array( 'CreditRed' => '1' ) );
	}
}
