<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules;

defined( 'ABSPATH' ) || exit;

abstract class AbstractModule {

	abstract public function slug(): string;

	abstract public function label(): string;

	abstract public function category(): string;

	abstract public function boot(): void;

	public function name(): string {
		return ucfirst( str_replace( '_', ' ', $this->slug() ) );
	}

	public function tagline(): string {
		return '';
	}

	public function methods(): array {
		return [];
	}

	public function settings_section(): string {
		return '';
	}
}
