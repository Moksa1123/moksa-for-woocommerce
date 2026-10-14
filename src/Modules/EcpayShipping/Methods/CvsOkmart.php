<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod;

defined( 'ABSPATH' ) || exit;

final class CvsOkmart extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_ecpay_shipping_cvs_okmart';
		$this->method_title       = __( 'ECPay — OK Mart pickup', 'moksa-for-woocommerce' );
		$this->method_description = __( 'ECPay OK Mart pickup.', 'moksa-for-woocommerce' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'ok';
	}

	public function carrier_label(): string {
		return __( 'OK', 'moksa-for-woocommerce' );
	}

	/** 綠界的 OK 只有店到店（OKMARTC2C），沒有 B2C；B2C 合約時不提供這個選項，以免下了單卻建不了物流單。 */
	public function logistics_sub_type(): string {
		return 'OKMARTC2C';
	}

	public function is_available( $package ): bool {
		if ( 'B2C' === get_option( 'moksafowo_ecpay_shipping_cvs_type', 'C2C' ) ) {
			return false;
		}
		return parent::is_available( $package );
	}
}
