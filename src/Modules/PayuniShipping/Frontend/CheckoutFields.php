<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Frontend;

defined( 'ABSPATH' ) || exit;

// Classic checkout `woocommerce_checkout_fields` filter handlers (Block 走 additional_fields schema 不經這條)
final class CheckoutFields {

	public static function setup_family_frozen_shipping_fields_requirements( $fields ) {
		$fields['shipping']['shipping_country']['required']   = false;
		$fields['shipping']['shipping_address_1']['required'] = false;
		$fields['shipping']['shipping_address_2']['required'] = false;
		$fields['shipping']['shipping_city']['required']      = false;
		$fields['shipping']['shipping_state']['required']     = false;
		$fields['shipping']['shipping_postcode']['required']  = false;
		$fields['shipping']['shipping_phone']['required']     = true;
		return $fields;
	}

	public static function setup_cvs_shipping_fields_requirements( $fields ) {
		$fields['shipping']['shipping_country']['required']   = false;
		$fields['shipping']['shipping_address_1']['required'] = false;
		$fields['shipping']['shipping_address_2']['required'] = false;
		$fields['shipping']['shipping_city']['required']      = false;
		$fields['shipping']['shipping_state']['required']     = false;
		$fields['shipping']['shipping_postcode']['required']  = false;
		$fields['shipping']['shipping_phone']['required']     = true;
		return $fields;
	}

	public static function setup_hd_shipping_fields_requirements( $fields ) {
		$fields['shipping']['shipping_phone']['required'] = true;
		return $fields;
	}

	public static function remove_shipping_phone_required( $fields ) {
		$fields['shipping']['shipping_phone']['required'] = false;
		return $fields;
	}
}
