<?php
namespace MoksaWeb\Mowc\Modules\PayuniShipping\Utils;

defined( 'ABSPATH' ) || exit;

trait TCatHDTrait {

	private $package_spec;
    
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
		
		// Check if payment method is COD
		$chosen_payment_method = WC()->session->get('chosen_payment_method');
		
		$total = WC()->cart->get_cart_contents_total();

		if ( $chosen_payment_method === 'cod' && ( $total < 30 || $total > 20000 ) ) {
			$is_available = false;
		}

		return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', $is_available, $package, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC core convention extension point.
	}

	public function init() {
		$this->init_settings();

		$this->instance_form_fields = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/PayuniShipping/Settings/TCat/HDFrozenFields.php';

		$this->title                    = $this->get_option( 'title' );
		$this->cost                     = $this->get_option( 'cost', 0 );
		$this->free_shipping_requires   = $this->get_option( 'free_shipping_requires' );
		$this->free_shipping_min_amount = $this->get_option( 'free_shipping_min_amount', 0 );
	}

}
