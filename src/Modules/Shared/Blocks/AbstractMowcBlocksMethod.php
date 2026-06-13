<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shared\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

abstract class AbstractMowcBlocksMethod extends AbstractPaymentMethodType {

	abstract protected function provider_slug(): string;

	public function __construct( string $gateway_id ) {
		$this->name = $gateway_id;
	}

	public function initialize(): void {
		$this->settings = (array) get_option( 'woocommerce_' . $this->name . '_settings', [] );
	}

	public function is_active(): bool {
		if ( 'yes' === ( $this->settings['enabled'] ?? null ) ) {
			return true;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return false;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! is_array( $gateways ) || ! isset( $gateways[ $this->name ] ) ) {
			return false;
		}
		$gateway = $gateways[ $this->name ];
		return $gateway instanceof \WC_Payment_Gateway && 'yes' === ( $gateway->enabled ?? 'no' );
	}

	protected function script_handle(): string {
		return 'mo-' . $this->provider_slug() . '-blocks';
	}

	protected function build_subdir(): string {
		return 'assets/blocks/build/methods/' . $this->provider_slug() . '/';
	}

	
	protected function default_deps(): array {
		return [ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ];
	}

	
	protected function extra_script_handles(): array {
		return [];
	}

	public function get_payment_method_script_handles(): array {
		$extras = $this->extra_script_handles();

		$handle    = $this->script_handle();
		$build_dir = MOKSAFOWO_PLUGIN_DIR . $this->build_subdir();
		$build_url = MOKSAFOWO_PLUGIN_URL . $this->build_subdir();
		$asset     = $build_dir . 'index.asset.php';

		$deps    = $this->default_deps();
		$version = MOKSAFOWO_VERSION;
		if ( file_exists( $asset ) ) {
			$loaded  = require $asset;
			$deps    = $loaded['dependencies'] ?? $deps;
			$version = $loaded['version'] ?? $version;
		}
		$js_path = $build_dir . 'index.js';
		if ( file_exists( $js_path ) ) {
			$version .= '.' . filemtime( $js_path );
		}

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, $build_url . 'index.js', array_merge( $deps, $extras ), $version, true );
			wp_set_script_translations( $handle, 'mo-ectools' );
		}
		return [ $handle ];
	}

	public function get_payment_method_data(): array {
		$title       = (string) ( $this->settings['title'] ?? '' );
		$description = (string) ( $this->settings['description'] ?? '' );

		$gateway = function_exists( 'WC' ) && WC()->payment_gateways
			? ( WC()->payment_gateways()->payment_gateways()[ $this->name ] ?? null )
			: null;

		if ( $gateway instanceof \WC_Payment_Gateway ) {
			if ( '' === $title ) {
				$title = (string) ( $gateway->title ?: $gateway->method_title );
			}
			if ( '' === $description ) {
				$description = (string) ( $gateway->description ?: $gateway->method_description );
			}
		}

		$supports = ( $gateway instanceof \WC_Payment_Gateway && ! empty( $gateway->supports ) )
			? array_values( $gateway->supports )
			: [ 'products' ];

		return $this->payment_method_data_extra( [
			'name'        => $this->name,
			'title'       => $title,
			'description' => $description,
			'supports'    => $supports,
		] );
	}

	
	protected function payment_method_data_extra( array $base ): array {
		return $base;
	}
}
