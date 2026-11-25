<?php
/**
 * 統一金流 Click to Pay（Mastercard／EMVCo 快速結帳）獨立按鈕。
 *
 * @package Moksafowo
 */

declare( strict_types=1 );

namespace Moksafowo\Modules\Payuni\Gateways;

use Moksafowo\Modules\Payuni\Api\PaymentRequest;

defined( 'ABSPATH' ) || exit;

/**
 * 付款請求帶 ClickToPay=1（官方 UPP 參數）；商店要先在統一金流後台「支付工具」開 Click to Pay。
 * 回傳 PaymentType=1（信用卡）、AuthType=8，付款結果、請款、退款都走信用卡流程，所以宣告 refunds。
 * 做成獨立按鈕（同 Apple Pay／Google Pay／Samsung Pay），預設不啟用。
 */
final class ClickToPay extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_clicktopay';

	public function __construct() {
		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi Click to Pay', 'moksa-for-woocommerce' );
		$this->method_description = __( 'Pay with cards saved in Click to Pay (Visa, Mastercard; not UnionPay or JCB). Turn on Click to Pay under Payment tools in the PAYUNi merchant dashboard first. The customer is redirected to the PAYUNi payment page.', 'moksa-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title                      = $this->get_option( 'title' );
		$this->description                = $this->get_option( 'description' );
		$this->incomplete_payment_message = $this->get_option( 'incomplete_payment_message' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'add_click_to_pay_flag' ), 10, 2 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                    => array(
				'title'   => __( 'Enable', 'moksa-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this payment method', 'moksa-for-woocommerce' ),
				'default' => 'no',
			),
			'title'                      => array(
				'title'       => __( 'Title', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The name customers see at checkout.', 'moksa-for-woocommerce' ),
				'default'     => __( 'Click to Pay', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'                => array(
				'title'       => __( 'Description', 'moksa-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'The description customers see at checkout, below the payment method name.', 'moksa-for-woocommerce' ),
				'default'     => __( 'Pay quickly with a card saved in Click to Pay.', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'incomplete_payment_message' => array(
				'title'       => __( 'Unfinished payment message', 'moksa-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'The message customers see on the order received page while the payment is still outstanding.', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * 付款頁只顯示 Click to Pay。
	 *
	 * @param array<string,mixed> $args  付款請求。
	 * @param \WC_Order           $order 訂單。
	 * @return array<string,mixed>
	 */
	public function add_click_to_pay_flag( $args, $order ) {
		$args['ClickToPay'] = '1';
		return $args;
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return ( new PaymentRequest() )->refund( $order_id, $amount, $reason );
	}

	public static function get_payment_order_metas() {
		return ApplePay::get_payment_order_metas();
	}
}
