<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Providers\SevenEleven;

use Moksafowo\Modules\PayuniShipping\Providers\ShippingBase;
use Moksafowo\Modules\PayuniShipping\Utils\CVSTrait;
use Moksafowo\Modules\PayuniShipping\Utils\GoodsType;
use Moksafowo\Modules\PayuniShipping\Utils\LgsType;
use Moksafowo\Modules\PayuniShipping\Utils\ShipType;

defined( 'ABSPATH' ) || exit;

class B2CFrozen extends ShippingBase {

	use CVSTrait;

	public const ID = 'moksafowo_payuni_shipping_711_b2c_frozen';

	public function __construct( $instance_id = 0 ) {
		parent::__construct();

		$this->instance_id        = absint( (int) $instance_id );
		$this->id                 = self::ID;
		$this->method_title       = __( 'PAYUNi — 7-11 大宗超商取貨（冷凍）', 'moksa-for-woocommerce' );
		$this->method_description = __( 'PAYUNi — 7-11 大宗超商取貨（冷凍）', 'moksa-for-woocommerce' );

		$this->goods_type = GoodsType::FROZEN;
		$this->lgs_type   = LgsType::B2C;
		$this->ship_type  = ShipType::SEVEN;

		$this->init();

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init(): void {
		$this->init_settings();

		$this->instance_form_fields = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/PayuniShipping/Settings/SevenEleven/B2CFrozenFields.php';

		$this->title                    = $this->get_option( 'title' );
		$this->cost                     = $this->get_option( 'cost', 0 );
		$this->free_shipping_requires   = $this->get_option( 'free_shipping_requires' );
		$this->free_shipping_min_amount = $this->get_option( 'free_shipping_min_amount', 0 );
		$this->ignore_discounts         = $this->get_option( 'ignore_discounts' );
	}
}
