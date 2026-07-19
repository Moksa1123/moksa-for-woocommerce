<?php
namespace Moksafowo\Modules\Payuni\Gateways;

use Moksafowo\Modules\Payuni\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

class Aftee extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_aftee';

	public static $order_metas;

	public function __construct() {

		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi AFTEE 無卡分期', 'moksa-for-woocommerce' );
		$this->method_description = __( 'AFTEE 先享後付無卡分期，跳轉至 PAYUNi 付款頁完成。', 'moksa-for-woocommerce' );
		$this->supports           = array(
			'products',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title                      = $this->get_option( 'title' );
		$this->description                = $this->get_option( 'description' );
		$this->min_amount                 = $this->get_option( 'min_amount' );
		$this->incomplete_payment_message = $this->get_option( 'incomplete_payment_message' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'moksafowo_payuni_payment_aftee_transaction_arrgs' ), 10, 2 );
	}

	public function init_form_fields() {
		$this->form_fields = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Payuni/Settings/AfteeSetting.php';
	}

	public function moksafowo_payuni_payment_aftee_transaction_arrgs( $args, $order ) {
		return array_merge(
			$args,
			array(
				'Aftee' => '1',
			)
		);
	}

	public static function get_payment_order_metas() {
		$order_metas =
		array(
			OrderMeta::AFTEE_PAY_NO   => _x( '付款序號', 'AFTEE', 'moksa-for-woocommerce' ),
			OrderMeta::AFTEE_PAY_TIME => __( '付款時間', 'moksa-for-woocommerce' ),
		);

		return $order_metas;
	}
}
