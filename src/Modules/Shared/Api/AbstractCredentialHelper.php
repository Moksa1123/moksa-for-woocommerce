<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shared\Api;

use MoksaWeb\Mowc\Logging\Logger;

defined( 'ABSPATH' ) || exit;

abstract class AbstractCredentialHelper {

	abstract protected static function option_prefix(): string;

	abstract protected static function log_source(): string;

	public static function is_sandbox(): bool {
		return 'yes' === get_option( static::option_prefix() . '_sandbox_enabled', 'yes' );
	}

	public static function log_enabled(): bool {
		return 'yes' === get_option( static::option_prefix() . '_debug_log_enabled', 'no' );
	}

	public static function log( string $message, array $context = [] ): void {
		if ( ! static::log_enabled() ) {
			return;
		}
		Logger::info( static::log_source(), $message, $context );
	}
}
