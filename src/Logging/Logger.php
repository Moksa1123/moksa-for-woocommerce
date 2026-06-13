<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Logging;

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
		// WC 官方 log 目錄常數優先；fallback 用 wp_upload_dir()（自訂 uploads / multisite 也正確）。
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
		// v0.5.69：對 message 也走 Redactor，補 P0-3 (v0.5.59) gap。
		// v0.5.67 曾嘗試但誤判 verify fail（後查證是 Playwright session logout 副作用，
		// 不是 redact_string 破壞），v0.5.68 revert。本版 v0.5.69 用 fresh-login verify
		// 確認 ECPay credit boundary 仍可跳轉 ECPay sandbox 後再加回。
		$message = Redactor::redact_string( $message );
		$context = Redactor::redact( $context );
		if ( $context !== [] ) {
			$message .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE );
		}

		if ( ! self::wc_logs_writable() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- fallback path when WC logger directory not writable; intentional last-resort to PHP error log.
			error_log( '[mo-ectools-' . $source . '][' . $level . '] ' . $message );
			return;
		}

		self::logger()->log(
			$level,
			$message,
			[ 'source' => 'mowp-' . $source ]
		);
	}
}
