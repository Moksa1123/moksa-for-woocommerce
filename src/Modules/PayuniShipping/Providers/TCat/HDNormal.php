<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Providers\TCat;

defined( 'ABSPATH' ) || exit;



use MoksaWeb\Mowc\Modules\PayuniShipping\Providers\ShippingBase;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShipType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\TCatHDTrait;

class HDNormal extends ShippingBase {

	use TCatHDTrait;

	public const ID = 'moksafowo_payuni_shipping_tcat_normal';

	public function __construct( $instance_id = 0 ) {

		parent::__construct();

		$this->instance_id        = absint( $instance_id );
		$this->id                 = self::ID;
		$this->method_title       = __( 'PAYUNi Shipping TCat Normal ', 'mo-ectools' );
		$this->method_description = __( 'PAYUNi Shipping TCat Normal ', 'mo-ectools' );
		
        $this->goods_type         = 1;//1=常溫，2=冷凍，3=冷藏
        $this->lgs_type           = 'HOME';
		$this->ship_type 	      = ShipType::TCAT; // 2=黑貓.

		$this->init();

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}
	
	public function init() {

		$this->init_settings();
		$this->instance_form_fields = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/PayuniShipping/Settings/TCat/HDNormalFields.php';

		$this->title                    = $this->get_option( 'title' );
		$this->cost                     = $this->get_option( 'cost', 0 );
		$this->free_shipping_requires   = $this->get_option( 'free_shipping_requires' );
		$this->free_shipping_min_amount = $this->get_option( 'free_shipping_min_amount', 0 );
		$this->ignore_discounts         = $this->get_option( 'ignore_discounts' );
		$this->package_spec             = $this->get_option( 'package_spec', 1 );//default 1=60
	}
}
