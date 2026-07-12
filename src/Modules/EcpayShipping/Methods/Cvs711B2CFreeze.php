<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class Cvs711B2CFreeze extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_ecpay_shipping_cvs_711_b2c_freeze';
		$this->method_title       = __( '綠界 — 7-11 B2C 冷凍店取', 'mo-ectools' );
		$this->method_description = __( '綠界 7-ELEVEN 冷凍超商取貨 — 適合冷凍食品電商，需向綠界申請大宗寄件服務。', 'mo-ectools' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return '711';
	}

	public function carrier_label(): string {
		return __( '7-11 冷凍', 'mo-ectools' );
	}

	public function logistics_sub_type(): string {
		return 'UNIMARTFREEZE';
	}

	public function supported_temperatures(): array {
		return [ ProductTemp::FROZEN => __( '冷凍', 'mo-ectools' ) ];
	}
}
