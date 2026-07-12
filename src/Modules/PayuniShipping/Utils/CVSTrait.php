<?php

namespace Moksafowo\Modules\PayuniShipping\Utils;

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
	}

	public function is_available( $package ) {

		$is_available = $this->is_enabled();

		$chosen_payment_method = WC()->session->get( 'chosen_payment_method' );
		$is_cod                = $chosen_payment_method === 'cod';
		$total                 = WC()->cart->get_cart_contents_total();
		// COD (service_type=3) 金額限制 1~20000
		if ( $is_cod && ( $total < 1 || $total > 20000 ) ) {
			$is_available = false;
		}

		// 本 trait 完全覆寫 is_available()(未呼叫 parent::is_available()),WC 核心
		// 不要求此擴充點使用特定 tag 名稱 —— 故走自家前綴,而非模仿 WC 核心慣例。
		return apply_filters( 'moksafowo_shipping_' . $this->id . '_is_available', $is_available, $package, $this );
	}
}
