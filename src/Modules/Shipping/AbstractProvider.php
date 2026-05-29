<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping;

defined( 'ABSPATH' ) || exit;

abstract class AbstractProvider {

	abstract public function provider_slug(): string;

	abstract public function provider_name(): string;

	abstract public function is_supported_method( string $method_id ): bool;

	abstract public function get_method_kind( string $method_id ): string;

	abstract public function get_method_carrier( string $method_id ): string;

	abstract public function meta_key_trade_no(): string;
	abstract public function meta_key_ship_no(): string;

	abstract public function boot(): void;
}
