<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Webhook;

defined( 'ABSPATH' ) || exit;

abstract class AbstractStatusMapper {

	abstract protected function provider_slug(): string;

	abstract protected function code_map(): array;

	public function handle_status_received( \WC_Order $order, string $code, string $desc = '' ): void {
		$provider = $this->provider_slug();

		do_action( "moksafowo_shipping_status_received_{$provider}_{$code}", $order, $desc );
		do_action( 'moksafowo_shipping_status_received', $order, $code, $desc, $provider );

		$slug = $this->code_map()[ $code ] ?? null;
		$slug = apply_filters( 'moksafowo_shipping_lgs_status_map', $slug, $code, $order, $provider );

		if ( null === $slug || $order->get_status() === $slug ) {
			return;
		}

		$provider_label_map = [
			'ecpay'  => __( '綠界', 'moksa-for-woocommerce' ),
			'payuni' => __( 'PAYUNi', 'moksa-for-woocommerce' ),
		];
		$provider_label     = $provider_label_map[ $provider ] ?? strtoupper( $provider );
		$order->update_status(
			$slug,
			sprintf(
				/* translators: 1: provider label, 2: description, 3: code */
				__( '%1$s 物流貨態：%2$s（狀態代碼 %3$s）', 'moksa-for-woocommerce' ),
				$provider_label,
				$desc,
				$code
			)
		);

		do_action( "moksafowo_shipping_status_changed_{$slug}", $order, $provider, $code );

		// mo-* 自訂狀態走自家 email；WC 既有狀態走 WC 內建 email pipeline
		if ( str_starts_with( $slug, 'mo-' ) ) {
			do_action( 'moksafowo_shipping_status_' . $slug . '_notification', $order->get_id(), $order );
		}
	}
}
