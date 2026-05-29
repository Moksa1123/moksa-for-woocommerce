<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Providers\SevenEleven;

defined( 'ABSPATH' ) || exit;



use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\ShippingBase;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\GoodsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\LgsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShipType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\CVSTrait;
class C2CFrozen extends ShippingBase {

	use CVSTrait;

	public const ID = 'mo_payuni_shipping_711_c2c_frozen';

	public function __construct( $instance_id = 0 ) {

		parent::__construct();

		$this->instance_id        = absint( $instance_id );
		$this->id                 = self::ID;
		$this->method_title       = __( 'PAYUNi Shipping 7-11 C2C Frozen', 'mo-ectools' );
		$this->method_description = __( 'PAYUNi Shipping 7-11 C2C Frozen', 'mo-ectools' );
		
		$this->goods_type		  = GoodsType::FROZEN; //1: 常溫, 2:冷凍
		$this->lgs_type		      = LgsType::C2C;
		$this->ship_type          = ShipType::SEVEN;

		$this->init();

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init() {
		$this->init_settings();

		$this->instance_form_fields = include MOWC_PLUGIN_DIR . 'src/Modules/PayuniShipping/Settings/SevenEleven/C2CFrozenFields.php';

		$this->title                    = $this->get_option( 'title' );
		$this->cost                     = $this->get_option( 'cost', 0 );
		$this->free_shipping_requires   = $this->get_option( 'free_shipping_requires' );
		$this->free_shipping_min_amount = $this->get_option( 'free_shipping_min_amount', 0 );
		$this->ignore_discounts         = $this->get_option( 'ignore_discounts' );
	}
}
