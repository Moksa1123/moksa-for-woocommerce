<?php
namespace Moksafowo\Modules\PayuniShipping\Utils;

defined( 'ABSPATH' ) || exit;

trait SingletonTrait {

	protected static $instance;

	protected function __construct() { }

	final protected function __clone() { }

	final public static function get_instance() {

		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}
}
