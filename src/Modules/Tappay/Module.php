<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay;

use Moksafowo\Modules\Shared\AbstractGatewayModule;
use Moksafowo\Modules\Tappay\Api\Helper;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractGatewayModule {

	public function slug(): string {
		return 'tappay';
	}

	public function label(): string {
		return __( 'TapPay 拍付 — 信用卡直連（TapPay Fields iframe，支援 3D 驗證）', 'moksa-for-woocommerce' );
	}

	public function name(): string {
		return __( 'TapPay 拍付', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( '信用卡直接在本站安全付款', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( '信用卡', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'tappay';
	}

	public static function gateway_map(): array {
		return [
			Gateways\Credit::GATEWAY_ID => Gateways\Credit::class,
		];
	}

	protected static function blocks_method_class(): string {
		return Blocks\TappayBlocksMethod::class;
	}

	protected static function uses_allowlist(): bool {
		return false;
	}

	protected function register_webhooks(): void {
		add_action( 'woocommerce_api_moksafowo_tappay_notify', [ Api\IpnHandler::class, 'handle_notify' ] );
		add_action( 'woocommerce_api_moksafowo_tappay_result', [ Api\IpnHandler::class, 'handle_result' ] );
	}

	protected function boot_extras(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_checkout_assets' ], 20 );
	}

	public static function enqueue_checkout_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( ! Helper::has_credentials() ) {
			return;
		}
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) ) {
			return;
		}

		wp_register_script( 'moksafowo-tappay-sdk', Helper::SDK_URL, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion,WordPress.WP.EnqueuedResourceParameters.NotInFooter

		$path    = MOKSAFOWO_PLUGIN_DIR . 'assets/public/moksafowo-tappay-fields.js';
		$version = file_exists( $path ) ? MOKSAFOWO_VERSION . '.' . filemtime( $path ) : MOKSAFOWO_VERSION;
		wp_register_script(
			'moksafowo-tappay-fields',
			MOKSAFOWO_PLUGIN_URL . 'assets/public/moksafowo-tappay-fields.js',
			[ 'jquery', 'moksafowo-tappay-sdk' ],
			$version,
			true
		);
		wp_localize_script(
			'moksafowo-tappay-fields',
			'moksafowoTappaySettings',
			[
				'gatewayId' => Gateways\Credit::GATEWAY_ID,
				'appId'     => (int) Helper::app_id(),
				'appKey'    => Helper::app_key(),
				'env'       => Helper::sdk_env(),
				'i18n'      => [
					'incomplete' => __( '請完整填寫信用卡資訊。', 'moksa-for-woocommerce' ),
					'primeError' => __( '無法取得付款憑證，請確認卡號是否正確。', 'moksa-for-woocommerce' ),
				],
			]
		);
		wp_enqueue_script( 'moksafowo-tappay-sdk' );
		wp_enqueue_script( 'moksafowo-tappay-fields' );
	}
}
