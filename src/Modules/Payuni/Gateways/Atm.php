<?php
namespace Moksafowo\Modules\Payuni\Gateways;

use Moksafowo\Modules\Payuni\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

class Atm extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_atm';

	public $expire_days;

	public function __construct() {

		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi ATM 虛擬帳號', 'moksa-for-woocommerce' );
		$this->method_description = __( '取得虛擬帳號後，於期限內至 ATM 轉帳完成付款。', 'moksa-for-woocommerce' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title                      = $this->get_option( 'title' );
		$this->description                = $this->get_option( 'description' );
		$this->expire_days                = $this->get_option( 'expire_days', 7 );
		$this->incomplete_payment_message = $this->get_option( 'incomplete_payment_message' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'moksafowo_payuni_payment_atm_transaction_arrgs' ), 10, 2 );
	}

	public function init_form_fields() {
		$this->form_fields = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Payuni/Settings/AtmSetting.php';
	}

	public function moksafowo_payuni_payment_atm_transaction_arrgs( $args, $order ) {

		return array_merge(
			$args,
			array(
				'ExpireDate' => gmdate( 'Y-m-d', strtotime( '+' . $this->expire_days . ' days' ) ),
				'ATM'        => '1',
			)
		);
	}

	public static function get_payment_order_metas() {
		$order_metas =
		array(
			OrderMeta::AMT_PAY_NO      => __( '虛擬帳號', 'moksa-for-woocommerce' ),
			OrderMeta::AMT_BANK_TYPE   => __( '銀行代碼', 'moksa-for-woocommerce' ),
			OrderMeta::AMT_EXPIRE_DATE => __( '繳費期限', 'moksa-for-woocommerce' ),
			OrderMeta::AMT_PAY_TIME    => __( '付款時間', 'moksa-for-woocommerce' ),
			OrderMeta::AMT_ACCOUNT_5NO => __( '帳號末五碼', 'moksa-for-woocommerce' ),
		);

		return $order_metas;
	}
}
