<?php

namespace Moksafowo\Modules\PayuniShipping\Providers;

defined( 'ABSPATH' ) || exit;

abstract class ShippingBase extends \WC_Shipping_Method {

	public $cost = 0;
	public $goods_type;

	public $lgs_type;

	public $ship_type;

	public $free_shipping_requires;

	public $free_shipping_min_amount;

	public $ignore_discounts;

	public function __construct() {
		$this->supports = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);
	}

	protected function get_cost() {

		if ( 'min_amount' === $this->free_shipping_requires ) {
			return ( $this->has_met_min_amount() ) ? 0 : $this->cost;
		}

		if ( 'coupon' === $this->free_shipping_requires ) {
			return ( $this->has_free_shipping_coupon() ) ? 0 : $this->cost;
		}

		if ( 'either' === $this->free_shipping_requires ) {
			return ( $this->has_met_min_amount() || $this->has_free_shipping_coupon() ) ? 0 : $this->cost;
		}

		if ( 'both' === $this->free_shipping_requires ) {
			return ( $this->has_met_min_amount() && $this->has_free_shipping_coupon() ) ? 0 : $this->cost;
		}

		return $this->cost;
	}

	private function has_met_min_amount() {
		$has_met_min_amount = false;

		$total = WC()->cart->get_displayed_subtotal();

		if ( 'no' === $this->ignore_discounts ) {
			$total = $total - WC()->cart->get_discount_total();
			if ( WC()->cart->display_prices_including_tax() ) {
				$total = $total - WC()->cart->get_discount_tax();
			}
		}

		$total = round( $total, wc_get_price_decimals() );

		if ( $total >= $this->free_shipping_min_amount ) {
			$has_met_min_amount = true;
		}

		return $has_met_min_amount;
	}

	private function has_free_shipping_coupon() {

		$has_coupon = false;
		$coupons    = WC()->cart->get_coupons();

		if ( $coupons ) {
			foreach ( $coupons as $code => $coupon ) {
				if ( $coupon->is_valid() && $coupon->get_free_shipping() ) {
					$has_coupon = true;
					break;
				}
			}
		}

		return $has_coupon;
	}
}
