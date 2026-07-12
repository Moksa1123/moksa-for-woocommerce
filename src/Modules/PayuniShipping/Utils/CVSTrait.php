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

		// 此 filter tag 名稱由 WooCommerce 核心規定:WC_Shipping_Method::is_available()
		// 預設實作本身就是 apply_filters('woocommerce_shipping_' . $this->id . '_is_available', ...);
		// 本 trait 覆寫 is_available() 加上金額限制邏輯後,仍須原樣呼叫同一 filter,才能維持
		// 「任何已註冊運送方式都可被其他外掛 filter 其可用性」的標準延伸點,非本外掛自訂前綴。
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC core `WC_Shipping_Method::is_available()` extension contract; tag name mandated by WooCommerce itself, not plugin-defined.
		return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', $is_available, $package, $this );
	}
}
