<?php
namespace Moksafowo\Modules\Payuni\Gateways;

use Moksafowo\Modules\Payuni\Api\PaymentRequest;
use Moksafowo\Modules\Payuni\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

class LinePay extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_linepay';

	public static $order_metas;

	public function __construct() {

		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi LINE Pay', 'moksa-for-woocommerce' );
		$this->method_description = __( '使用 LINE Pay 付款，跳轉至 PAYUNi 付款頁完成。', 'moksa-for-woocommerce' );
		$this->supports           = array(
			'products',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title                      = $this->get_option( 'title' );
		$this->description                = $this->get_option( 'description' );
		$this->incomplete_payment_message = $this->get_option( 'incomplete_payment_message' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'moksafowo_payuni_payment_linepay_transaction_arrgs' ), 10, 2 );
	}

	public function init_form_fields() {
		$this->form_fields = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Payuni/Settings/LinePaySetting.php';
	}

	public function moksafowo_payuni_payment_linepay_transaction_arrgs( $args, $order ) {
		return array_merge(
			$args,
			array(
				'LinePay' => '1',
			)
		);
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$request = new PaymentRequest( $this );
		return $request->refund( $order_id, $amount, $reason );
	}

	public static function get_payment_order_metas() {
		$order_metas =
		array(
			OrderMeta::LINE_PAY_NO => _x( '付款序號', 'LINE Pay', 'moksa-for-woocommerce' ),
		);

		return $order_metas;
	}
}
