<?php
namespace Moksafowo\Modules\Payuni\Gateways;

use Moksafowo\Modules\Payuni\Api\PaymentRequest;
use Moksafowo\Modules\Payuni\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

class ApplePay extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_applepay';

	public static $order_metas;

	public function __construct() {

		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi Apple Pay', 'moksa-for-woocommerce' );
		$this->method_description = __( 'Pay quickly with Apple Pay, which needs Safari or an iOS device. The customer is redirected to the PAYUNi payment page.', 'moksa-for-woocommerce' );
		$this->supports           = array(
			'products',
			'refunds',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title                      = $this->get_option( 'title' );
		$this->description                = $this->get_option( 'description' );
		$this->incomplete_payment_message = $this->get_option( 'incomplete_payment_message' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'moksafowo_payuni_payment_applepay_transaction_arrgs' ), 10, 2 );
	}

	public function init_form_fields() {
		$this->form_fields = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Payuni/Settings/ApplePaySetting.php';
	}

	public function moksafowo_payuni_payment_applepay_transaction_arrgs( $args, $order ) {
		return array_merge(
			$args,
			array(
				'ApplePay' => '1',
			)
		);
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$request = new PaymentRequest( $this );
		return $request->refund( $order_id, $amount, $reason );
	}

	public static function get_payment_order_metas() {
		return array(
			OrderMeta::CREDIT_AUTH_TYPE  => __( 'Authorization method', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_AUTH_DAY   => __( 'Authorization date', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_AUTH_TIME  => __( 'Authorized at', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_AUTH_CODE  => __( 'Bank authorization code', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_CARD_4NO   => __( 'Last four card digits', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_BANK       => __( 'Issuing bank', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_LOCATION   => __( 'Foreign card', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_ECI        => __( '3-D Secure ECI', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_RED_AMT    => __( 'Reward points redeemed', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_RED_NO     => __( 'Reward redemption ID', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_TOKEN_ID   => __( 'Token ID', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_TOKEN_LIFE => __( 'Token expiry', 'moksa-for-woocommerce' ),
		);
	}
}
