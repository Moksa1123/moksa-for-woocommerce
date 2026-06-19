<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shared;

use MoksaWeb\Mowc\Modules\AbstractModule;
use MoksaWeb\Mowc\Modules\Shared\Setup\GatewayAllowlistMigrator;

defined( 'ABSPATH' ) || exit;

abstract class AbstractGatewayModule extends AbstractModule {


	abstract public static function gateway_map(): array;


	abstract protected static function blocks_method_class(): string;

	public function category(): string {
		return 'payment';
	}

	protected static function unified_gateway_id(): ?string {
		return null;
	}

	protected static function uses_allowlist(): bool {
		return true;
	}

	protected static function uses_display_mode(): bool {
		return null !== static::unified_gateway_id();
	}

	public function boot(): void {
		if ( static::uses_allowlist() ) {
			$unified      = static::unified_gateway_id();
			$seed_methods = null !== $unified
				? array_values( array_filter( array_keys( static::gateway_map() ), static fn( string $id ): bool => $id !== $unified ) )
				: array_keys( static::gateway_map() );
			GatewayAllowlistMigrator::seed_if_unseeded( $this->slug(), $seed_methods );
		}

		add_filter( 'woocommerce_payment_gateways', [ static::class, 'register_gateways' ] );

		if ( '' !== static::blocks_method_class() ) {
			add_action( 'woocommerce_blocks_payment_method_type_registration', [ static::class, 'register_block_methods' ] );
		}

		$this->register_webhooks();
		$this->boot_extras();
	}

	abstract protected function register_webhooks(): void;

	protected function boot_extras(): void {}


	public static function should_register_gateway( string $id, ?string $mode = null, ?array $allowlist = null ): bool {
		$slug    = static::provider_slug();
		$unified = static::unified_gateway_id();

		if ( static::uses_display_mode() ) {
			$resolved_mode = $mode ?? (string) get_option( 'moksafowo_' . $slug . '_display_mode', 'multi' );
			if ( 'single' === $resolved_mode ) {
				return $id === $unified;
			}
			if ( $id === $unified ) {
				return false;
			}
		}

		if ( ! static::uses_allowlist() ) {
			return true;
		}

		$resolved_allowlist = $allowlist ?? (array) get_option( 'moksafowo_' . $slug . '_enabled_methods', [] );
		return in_array( $id, $resolved_allowlist, true );
	}

	public static function register_gateways( array $gateways ): array {
		// Block Store API 試算每次 cart update 都 fire WC()->payment_gateways() →
		// register_gateways → foreach gateway × 2 get_option。ECPay 17 + PAYUNi 20 等
		// gateway loop 變成數百次 get_option。entry 一次性 fetch 兩個 option 傳給 callee。
		[ $mode, $allowlist ] = static::fetch_gateway_options();
		foreach ( static::gateway_map() as $id => $class ) {
			if ( static::should_register_gateway( (string) $id, $mode, $allowlist ) ) {
				$gateways[] = $class;
			}
		}
		return $gateways;
	}

	public static function register_block_methods( $registry ): void {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		$blocks_class = static::blocks_method_class();
		if ( '' === $blocks_class ) {
			return;
		}
		[ $mode, $allowlist ] = static::fetch_gateway_options();
		foreach ( array_keys( static::gateway_map() ) as $gateway_id ) {
			if ( ! static::should_register_gateway( (string) $gateway_id, $mode, $allowlist ) ) {
				continue;
			}
			$registry->register( new $blocks_class( (string) $gateway_id ) );
		}
	}


	protected static function fetch_gateway_options(): array {
		$slug      = static::provider_slug();
		$mode      = static::uses_display_mode() ? (string) get_option( 'moksafowo_' . $slug . '_display_mode', 'multi' ) : null;
		$allowlist = static::uses_allowlist() ? (array) get_option( 'moksafowo_' . $slug . '_enabled_methods', [] ) : null;
		return [ $mode, $allowlist ];
	}


	protected static function provider_slug(): string {
		return ( new static() )->slug();
	}
}
