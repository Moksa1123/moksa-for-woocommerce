<?php

declare( strict_types=1 );

namespace Moksafowo\Logging;

defined( 'ABSPATH' ) || exit;

final class Logger {

	private static ?\WC_Logger $logger = null;

	private static bool $wc_log_writable = true;

	private static function logger(): \WC_Logger {
		if ( null === self::$logger ) {
			$instance     = wc_get_logger();
			self::$logger = $instance;
		}
		return self::$logger;
	}

	private static function wc_logs_writable(): bool {
		if ( ! self::$wc_log_writable ) {
			return false;
		}
		$dir = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs';
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			self::$wc_log_writable = false;
			return false;
		}
		return true;
	}

	public static function info( string $source, string $message, array $context = [] ): void {
		self::log( 'info', $source, $message, $context );
	}

	public static function warning( string $source, string $message, array $context = [] ): void {
		self::log( 'warning', $source, $message, $context );
	}

	public static function error( string $source, string $message, array $context = [] ): void {
		self::log( 'error', $source, $message, $context );
	}

	public static function debug( string $source, string $message, array $context = [] ): void {
		self::log( 'debug', $source, $message, $context );
	}

	private static function log( string $level, string $source, string $message, array $context ): void {
		$message = Redactor::redact_string( $message );
		$context = Redactor::redact( $context );
		if ( $context !== [] ) {
			$message .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE );
		}

		if ( ! self::wc_logs_writable() ) {
			// WC 的 log 目錄不能寫時，只在開了 WP_DEBUG 的站上留下警告。
			wp_trigger_error( __METHOD__, '[moksa-for-woocommerce-' . $source . '][' . $level . '] ' . $message, E_USER_NOTICE );
			return;
		}

		self::logger()->log(
			$level,
			$message,
			[ 'source' => 'moksafowo-' . $source ]
		);
	}
}
