<?php
namespace MoksaWeb\Mowc\Modules\Payuni\Gateways;

use MoksaWeb\Mowc\Modules\Payuni\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

class Cvs extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_cvs';

	public $expire_days;

	public function __construct() {

		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi 超商代碼繳費', 'mo-ectools' );
		$this->method_description = __( '取得超商代碼後到 7-11 / 全家 / 萊爾富 / OK 任一門市繳費。', 'mo-ectools' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title                      = $this->get_option( 'title' );
		$this->description                = $this->get_option( 'description' );
		$this->expire_days                = $this->get_option( 'expire_days', 7 );
		$this->incomplete_payment_message = $this->get_option( 'incomplete_payment_message' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'moksafowo_payuni_payment_cvs_transaction_arrgs' ), 10, 2 );
	}

	public function init_form_fields() {
		$this->form_fields = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Payuni/Settings/CvsSetting.php';
	}

	public function moksafowo_payuni_payment_cvs_transaction_arrgs( $args, $order ) {

		return array_merge(
			$args,
			array(
				'ExpireDate' => gmdate( 'Y-m-d', strtotime( '+' . $this->expire_days . ' days' ) ),
				'CVS'        => '1',
			)
		);
	}

	public static function get_payment_order_metas() {
		$order_metas =
		array(
			OrderMeta::CVS_PAY_NO      => __( '繳費代碼', 'mo-ectools' ),
			OrderMeta::CVS_STORE       => __( '超商門市', 'mo-ectools' ),
			OrderMeta::CVS_EXPIRE_DATE => __( '繳費期限', 'mo-ectools' ),
		);

		return $order_metas;
	}
}
