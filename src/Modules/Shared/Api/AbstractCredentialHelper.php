<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Api;

use Moksafowo\Logging\Logger;

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

	/**
	 * 驗章失敗、來源不符這類「可能有人偽造」的事件：不受除錯開關影響，一律以 warning 記錄。
	 * 商家多半沒開除錯 log，出事時才有線索可查。
	 */
	public static function warn( string $message, array $context = [] ): void {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by FILTER_VALIDATE_IP.
		if ( false !== $ip ) {
			$context['remote_ip'] = $ip;
		}
		Logger::warning( static::log_source(), $message, $context );
	}

	public static function log( string $message, array $context = [] ): void {
		if ( ! static::log_enabled() ) {
			return;
		}
		Logger::info( static::log_source(), $message, $context );
	}
}
