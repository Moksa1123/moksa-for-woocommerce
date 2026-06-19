<?php
namespace MoksaWeb\Mowc\Modules\Payuni\Gateways;

use MoksaWeb\Mowc\Modules\Payuni\Api\PaymentRequest;
use MoksaWeb\Mowc\Modules\Payuni\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

class CreditUnionPay extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_unionpay';

	public function __construct() {

		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi 銀聯卡', 'mo-ectools' );
		$this->method_description = __( '銀聯卡跨境付款，跳轉至 PAYUNi 付款頁完成。', 'mo-ectools' );
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
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'moksafowo_payuni_payment_credit_transaction_arrgs' ), 10, 2 );
	}

	public function init_form_fields() {
		$this->form_fields = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Payuni/Settings/CreditUnionPay.php';
	}

	public function moksafowo_payuni_payment_credit_transaction_arrgs( $args, $order ) {
		return array_merge(
			$args,
			array(
				'CreditUnionPay' => '1',
				'Union3D'        => '1',
			)
		);
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$request = new PaymentRequest();
		return $request->refund( $order_id, $amount, $reason );
	}

	public static function get_payment_order_metas() {
		return array(
			OrderMeta::CREDIT_AUTH_TYPE  => __( '授權方式', 'mo-ectools' ),
			OrderMeta::CREDIT_AUTH_DAY   => __( '授權日期', 'mo-ectools' ),
			OrderMeta::CREDIT_AUTH_TIME  => __( '授權時間', 'mo-ectools' ),
			OrderMeta::CREDIT_AUTH_CODE  => __( '銀行授權碼', 'mo-ectools' ),
			OrderMeta::CREDIT_CARD_4NO   => __( '卡號末四碼', 'mo-ectools' ),
			OrderMeta::CREDIT_BANK       => __( '發卡銀行', 'mo-ectools' ),
			OrderMeta::CREDIT_LOCATION   => __( '境外卡', 'mo-ectools' ),
			OrderMeta::CREDIT_ECI        => __( '3D 驗證 ECI', 'mo-ectools' ),
			OrderMeta::CREDIT_RED_AMT    => __( '紅利折抵金額', 'mo-ectools' ),
			OrderMeta::CREDIT_RED_NO     => __( '紅利折抵序號', 'mo-ectools' ),
			OrderMeta::CREDIT_TOKEN_ID   => __( 'Token 編號', 'mo-ectools' ),
			OrderMeta::CREDIT_TOKEN_LIFE => __( 'Token 有效期', 'mo-ectools' ),
		);
	}
}
