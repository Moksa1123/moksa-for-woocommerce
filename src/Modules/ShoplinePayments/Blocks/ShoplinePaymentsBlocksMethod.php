<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments\Blocks;

use Moksafowo\Modules\Shared\Blocks\AbstractMowcBlocksMethod;
use Moksafowo\Modules\ShoplinePayments\Api\Helper;
use Moksafowo\Modules\ShoplinePayments\Gateways\CardGateway;

defined( 'ABSPATH' ) || exit;

final class ShoplinePaymentsBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		// 內嵌式信用卡要掛 SDK 收銀台，是另一支手寫的區塊元件。
		return CardGateway::GATEWAY_ID === $this->name ? 'shopline-card' : 'shopline-payments';
	}

	protected function extra_script_handles(): array {
		if ( CardGateway::GATEWAY_ID !== $this->name ) {
			return [];
		}
		if ( ! wp_script_is( 'moksafowo-slp-sdk', 'registered' ) ) {
			wp_register_script( 'moksafowo-slp-sdk', Helper::SDK_URL, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- 官方 CDN SDK，版本由路徑 /v1/ 決定。
		}
		return [ 'moksafowo-slp-sdk' ];
	}

	public function is_active(): bool {
		if ( CardGateway::GATEWAY_ID === $this->name && '' === Helper::client_key() ) {
			return false;
		}
		return parent::is_active();
	}

	protected function payment_method_data_extra( array $base ): array {
		if ( CardGateway::GATEWAY_ID !== $this->name ) {
			return $base;
		}
		// 沒填說明時不要退回後台用的 method_description（那是寫給商家看的）。
		$base['description'] = (string) ( $this->settings['description'] ?? '' );
		$base['clientKey']   = Helper::client_key();
		$base['merchantId']  = Helper::merchant_id();
		$base['env']         = Helper::sdk_env();
		$base['bindCard']    = \Moksafowo\Compatibility\Subscriptions::active()
			&& class_exists( 'WC_Subscriptions_Cart' )
			&& \WC_Subscriptions_Cart::cart_contains_subscription();
		$base['i18n']        = [
			'loadError' => __( 'The card form could not be loaded. Please refresh the page or choose another payment method.', 'moksa-for-woocommerce' ),
			'payError'  => __( 'The card payment did not go through. Please check the card and try again.', 'moksa-for-woocommerce' ),
		];
		return $base;
	}
}
