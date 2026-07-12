<?php
namespace Moksafowo\Modules\Payuni\Utils;

defined( 'ABSPATH' ) || exit;

trait SingletonTrait {

	protected static $instance;

	protected function __construct() { }

	final protected function __clone() { }

	final public static function get_instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
