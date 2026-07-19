<?php
namespace Moksafowo\Modules\Payuni\Gateways;

defined( 'ABSPATH' ) || exit;

class CreditInstallment9 extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_installment_9';

	use TraitCreditInstallment;

	public function __construct() {

		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi 信用卡分期 9 期', 'moksa-for-woocommerce' );
		$this->method_description = __( '信用卡分 9 期付款，跳轉至 PAYUNi 付款頁完成。', 'moksa-for-woocommerce' );

		$this->init_installment( 9, $this->min_amount );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->min_amount  = $this->get_option( 'min_amount' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'moksafowo_payuni_payment_installment_transaction_arrgs' ), 10, 2 );
	}
}//end class
