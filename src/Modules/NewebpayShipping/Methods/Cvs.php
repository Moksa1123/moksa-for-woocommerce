<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\NewebpayShipping\Methods;

use MoksaWeb\Mowc\Modules\Shipping\Methods\AbstractCvsShippingMethod;

defined( 'ABSPATH' ) || exit;

final class Cvs extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'mo_newebpay_shipping_cvs';
		$this->method_title       = __( '藍新 — 超商取貨', 'mo-ectools' );
		$this->method_description = __( '藍新超商取貨。建議搭配藍新超商代碼付款，可於同一流程選擇取貨門市。', 'mo-ectools' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'newebpay_cvs';
	}

	public function carrier_label(): string {
		return __( '藍新 超商', 'mo-ectools' );
	}
}
