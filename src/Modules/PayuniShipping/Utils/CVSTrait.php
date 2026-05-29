<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Utils;

defined( 'ABSPATH' ) || exit;

trait CVSTrait {

    	public function calculate_shipping( $package = array() ) {
		$rate = array(
			'id'      => $this->get_rate_id(),
			'label'   => $this->title,
			'cost'    => $this->get_cost(),
			'taxes'   => true,
			'package' => $package,
		);
		$this->add_rate( $rate );
		do_action( 'woocommerce_' . $this->id . '_shipping_add_rate', $this, $rate );
	}

    	public function is_available( $package ) {

		$is_available = $this->is_enabled();
		
		// Check if payment method is COD
		$chosen_payment_method = WC()->session->get('chosen_payment_method');
		$is_cod = $chosen_payment_method === 'cod';
		
		$total = WC()->cart->get_cart_contents_total();

		// 取貨付款 (service_type = 3), 金額限制為 1~20000
		// Only apply amount restrictions if payment method is COD
		if ( $is_cod && ( $total < 1 || $total > 20000 ) ) {
			$is_available = false;
		}

		return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', $is_available, $package, $this );
	}
}