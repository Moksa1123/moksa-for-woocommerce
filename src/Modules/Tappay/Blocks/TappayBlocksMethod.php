<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Blocks;

use Moksafowo\Modules\Shared\Blocks\AbstractMowcBlocksMethod;
use Moksafowo\Modules\Tappay\Api\Helper;

defined( 'ABSPATH' ) || exit;

final class TappayBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'tappay';
	}

	public function is_active(): bool {
		if ( ! Helper::has_credentials() ) {
			return false;
		}
		return parent::is_active();
	}

	protected function extra_script_handles(): array {
		$sdk_handle = 'moksafowo-tappay-sdk';
		if ( ! wp_script_is( $sdk_handle, 'registered' ) ) {
			wp_register_script( $sdk_handle, Helper::SDK_URL, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion,WordPress.WP.EnqueuedResourceParameters.NotInFooter
		}
		return [ $sdk_handle ];
	}

	protected function payment_method_data_extra( array $base ): array {
		$base['appId']  = (int) Helper::app_id();
		$base['appKey'] = Helper::app_key();
		$base['env']    = Helper::sdk_env();

		// 錢包 / 行動支付的區塊元件要知道該呼叫哪個 TPDirect 命名空間才能取 prime。
		// 信用卡沒有這個方法（走 TPDirect.card），維持不帶。
		$gateway = function_exists( 'WC' ) && WC()->payment_gateways
			? ( WC()->payment_gateways()->payment_gateways()[ $this->name ] ?? null )
			: null;
		if ( $gateway && method_exists( $gateway, 'sdk_namespace' ) ) {
			$base['sdkNamespace'] = (string) $gateway->sdk_namespace();
		}
		return $base;
	}
}
