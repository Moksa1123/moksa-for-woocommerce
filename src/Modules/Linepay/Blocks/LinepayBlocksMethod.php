<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use MoksaWeb\Mowc\Modules\Linepay\Constants;

defined( 'ABSPATH' ) || exit;

final class LinepayBlocksMethod extends AbstractPaymentMethodType {

	protected $name = Constants::ID;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_moksafowo-linepay_settings', array() );
	}

	public function is_active() {
		return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
	}

	public function get_payment_method_script_handles() {

		$js_path = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Linepay/assets/js/blocks/linepay.js';
		$ver     = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MOKSAFOWO_VERSION;
		wp_register_script(
			'moksafowo-linepay-blocks',
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/Linepay/assets/js/blocks/linepay.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			$ver,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'moksafowo-linepay-blocks', 'mo-ectools' );
		}

		return array( 'moksafowo-linepay-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
		);
	}
}
