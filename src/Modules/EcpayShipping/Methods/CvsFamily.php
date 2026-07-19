<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod;

defined( 'ABSPATH' ) || exit;

final class CvsFamily extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_ecpay_shipping_cvs_family';
		$this->method_title       = __( '綠界 — 全家取貨', 'moksa-for-woocommerce' );
		$this->method_description = __( '綠界全家取貨。', 'moksa-for-woocommerce' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'fami';
	}

	public function carrier_label(): string {
		return __( '全家', 'moksa-for-woocommerce' );
	}

	public function logistics_sub_type(): string {
		return 'B2C' === get_option( 'moksafowo_ecpay_shipping_cvs_type', 'C2C' ) ? 'FAMI' : 'FAMIC2C';
	}
}
