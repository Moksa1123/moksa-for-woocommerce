<?php
/**
 * 統一金流「取貨付款」：分開顯示時的獨立按鈕，付款頁只顯示取貨付款。
 *
 * @package Moksafowo
 */

declare( strict_types=1 );

namespace Moksafowo\Modules\Payuni\Gateways;

use Moksafowo\Modules\PayuniShipping\CreateMode;
use Moksafowo\Modules\PayuniShipping\Utils\MethodIdPredicates;

defined( 'ABSPATH' ) || exit;

/**
 * 只在統一物流「幕前」模式、而且運送方式是統一物流 7-ELEVEN 時出現。
 * 付款請求帶 Ship=1、不帶 ShipTag 與任何付款工具 → 統一金流付款頁只剩取貨付款
 * （官方文件 UPP 物流參數備註 2）。物流欄位由 PayuniShipping\Frontstage::add_shipping_args 帶。
 * 錢在取貨時由門市收，網站沒有可退的刷卡交易，所以不宣告 refunds。
 */
final class PickupPay extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_pickup_pay';

	public function __construct() {
		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi pay on pickup (7-ELEVEN)', 'moksa-for-woocommerce' );
		$this->method_description = __( 'The customer pays when collecting the parcel at 7-ELEVEN. Only offered with PAYUNi 7-ELEVEN shipping when the shipment is created on the PAYUNi payment page. Your PAYUNi account must have pay on pickup enabled.', 'moksa-for-woocommerce' );
		$this->order_button_text  = __( 'Place order', 'moksa-for-woocommerce' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'moksa-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PAYUNi pay on pickup', 'moksa-for-woocommerce' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'   => __( 'Title', 'moksa-for-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Pay on pickup at 7-ELEVEN', 'moksa-for-woocommerce' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'moksa-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Choose your pickup store on the next page and pay when you collect the parcel.', 'moksa-for-woocommerce' ),
			),
		);
	}

	public function is_available() {
		if ( ! parent::is_available() || ! CreateMode::is_frontend() ) {
			return false;
		}
		$method_id = self::current_shipping_method_id();
		// 後台（付款設定列表等）沒有購物車，照設定顯示。
		if ( null === $method_id ) {
			return true;
		}
		return '' !== $method_id && MethodIdPredicates::is_payuni_shipping( $method_id ) && MethodIdPredicates::needs_cvs( $method_id );
	}

	/**
	 * 目前要付款的運送方式 ID：訂單付款頁看訂單，結帳頁看工作階段；都不是（例如後台）回 null。
	 */
	private static function current_shipping_method_id(): ?string {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			return $order instanceof \WC_Order ? \Moksafowo\Modules\PayuniShipping\Frontstage::order_method_id( $order ) : '';
		}
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return null;
		}
		foreach ( (array) WC()->session->get( 'chosen_shipping_methods', array() ) as $chosen ) {
			$mid = strstr( (string) $chosen, ':', true );
			$mid = false === $mid ? (string) $chosen : $mid;
			if ( MethodIdPredicates::is_payuni_shipping( $mid ) ) {
				return $mid;
			}
		}
		return '';
	}
}
