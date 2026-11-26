<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Payuni\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

final class PayuniBlocksMethod extends AbstractPaymentMethodType {

	public function __construct( string $gateway_id ) {
		$this->name = $gateway_id;
	}

	public function initialize(): void {
		$this->settings = (array) get_option( 'woocommerce_' . $this->name . '_settings', [] );
	}

	public function is_active(): bool {
		// Option may not exist yet (admin never opened "Save changes" on the
		// gateway). Consult the running gateway class so the form-field
		// defaults still light up the Block adapter.
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

	public function get_payment_method_script_handles(): array {
		$handle    = 'moksafowo-payuni-blocks';
		$build_dir = MOKSAFOWO_PLUGIN_DIR . 'assets/blocks/build/methods/payuni/';
		$build_url = MOKSAFOWO_PLUGIN_URL . 'assets/blocks/build/methods/payuni/';
		$asset     = $build_dir . 'index.asset.php';

		$deps    = [ 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' ];
		$version = MOKSAFOWO_VERSION;
		if ( file_exists( $asset ) ) {
			$loaded  = require $asset;
			$deps    = $loaded['dependencies'] ?? $deps;
			$version = $loaded['version'] ?? $version;
		}

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, $build_url . 'index.js', $deps, $version, true );
			wp_set_script_translations( $handle, 'moksa-for-woocommerce' );
		}
		if ( ! $this->is_embed() ) {
			return [ $handle ];
		}
		// 免跳轉信用卡：另一支手寫的區塊元件，依賴統一金流官方 SDK。
		$embed      = 'moksafowo-payuni-embed-blocks';
		$embed_path = MOKSAFOWO_PLUGIN_DIR . 'assets/blocks/build/methods/payuni-embed/index.js';
		if ( ! wp_script_is( $embed, 'registered' ) ) {
			$embed_asset = MOKSAFOWO_PLUGIN_DIR . 'assets/blocks/build/methods/payuni-embed/index.asset.php';
			$embed_deps  = file_exists( $embed_asset ) ? ( ( require $embed_asset )['dependencies'] ?? [] ) : [];
			\Moksafowo\Modules\Payuni\Embed\Embed::register_sdk();
			wp_register_script(
				$embed,
				MOKSAFOWO_PLUGIN_URL . 'assets/blocks/build/methods/payuni-embed/index.js',
				$embed_deps,
				file_exists( $embed_path ) ? MOKSAFOWO_VERSION . '.' . filemtime( $embed_path ) : MOKSAFOWO_VERSION,
				true
			);
			$css = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Payuni/assets/css/embed.css';
			wp_enqueue_style(
				'moksafowo-payuni-embed',
				MOKSAFOWO_PLUGIN_URL . 'src/Modules/Payuni/assets/css/embed.css',
				[],
				file_exists( $css ) ? MOKSAFOWO_VERSION . '.' . filemtime( $css ) : MOKSAFOWO_VERSION
			);
		}
		return [ $handle, $embed ];
	}

	/** 這個付款方式是開了免跳轉的統一金流信用卡。 */
	private function is_embed(): bool {
		return \Moksafowo\Modules\Payuni\Gateways\Credit::GATEWAY_ID === $this->name && \Moksafowo\Modules\Payuni\Embed\Embed::enabled();
	}

	public function get_payment_method_data(): array {
		$title       = (string) ( $this->settings['title'] ?? '' );
		$description = (string) ( $this->settings['description'] ?? '' );

		// Option may not exist yet (admin never opened "Save changes"). Fall
		// back to the running gateway's `method_title` / `method_description`
		// so the Block label is human-readable from first activation.
		if ( '' === $title || '' === $description ) {
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
		}

		$data = [
			'name'        => $this->name,
			'title'       => $title,
			'description' => $description,
			'supports'    => $this->get_supported_features(),
		];
		if ( $this->is_embed() ) {
			$data['embed'] = \Moksafowo\Modules\Payuni\Embed\Embed::client_config();
			// 卡號欄位本身就說明了怎麼付；商家沒寫說明時不拿後台用的方法說明頂替。
			$data['description'] = (string) ( $this->settings['description'] ?? '' );
		}
		return $data;
	}

	public function get_supported_features(): array {
		// 照 gateway 實際宣告：開啟訂閱的信用卡要帶 subscriptions，區塊結帳才會在訂閱購物車顯示。
		$gateway = function_exists( 'WC' ) && WC()->payment_gateways
			? ( WC()->payment_gateways()->payment_gateways()[ $this->name ] ?? null )
			: null;
		return $gateway instanceof \WC_Payment_Gateway && ! empty( $gateway->supports )
			? array_values( $gateway->supports )
			: [ 'products', 'refunds' ];
	}
}
