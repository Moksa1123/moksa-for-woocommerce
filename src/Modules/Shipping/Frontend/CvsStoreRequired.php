<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Frontend;

use Moksafowo\Modules\Shipping\Admin\CvsStoreEditor;

defined( 'ABSPATH' ) || exit;

/**
 * 選了超商取貨就一定要選門市，否則不准下單。
 *
 * 沒有這道關卡的話會產生「出不了貨的訂單」：建物流單需要門市編號，
 * 沒有就得回頭問顧客，而顧客通常已經離站了。
 *
 * 兩條結帳路徑分開擋，因為機制完全不同：
 * - Classic：`woocommerce_checkout_process` 時訂單還不存在，只能看 session，
 *   所以門市編號由各家自己從 session 取（descriptor 的 session_store_id）。
 * - Block：掛在各家 save_to_order（priority 20）之後的 30，這時門市已經寫進
 *   訂單 meta，直接讀 descriptor 的 meta key 就好，不用知道各家的 session 鍵。
 */
final class CvsStoreRequired {

	public static function init(): void {
		add_action( 'woocommerce_checkout_process', [ __CLASS__, 'validate_classic' ] );
		// priority 30 —— 一定要在各家 save_to_order（20）之後，否則永遠讀不到門市
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'validate_block' ], 30, 2 );
	}

	public static function validate_classic(): void {
		// 跟 WC core process_checkout() 驗同一顆 nonce
		if ( ! isset( $_POST['woocommerce-process-checkout-nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['woocommerce-process-checkout-nonce'] ) ), 'woocommerce-process_checkout' ) ) {
			return;
		}

		$chosen = isset( $_POST['shipping_method'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['shipping_method'] ) )
			: [];
		if ( empty( $chosen ) ) {
			return;
		}

		$match = self::match_chosen( $chosen );
		if ( null === $match ) {
			return;
		}

		if ( '' !== self::session_store_id( $match['provider'] ) ) {
			return;
		}

		wc_add_notice( self::message( $match['provider'] ), 'error' );
	}

	/**
	 * @param \WC_Order $order   Order being created.
	 * @param mixed     $request Store API request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When no pickup store was chosen.
	 * @throws \Exception When no pickup store was chosen and the Store API exception class is unavailable.
	 */
	public static function validate_block( $order, $request = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		// 換金流 / 換物流時 Block 會打 __experimental_calc_totals=true，那不是下單
		if ( $request && method_exists( $request, 'get_param' ) && $request->get_param( '__experimental_calc_totals' ) ) {
			return;
		}

		$match = CvsStoreEditor::match( $order );
		if ( null === $match ) {
			return;
		}

		$meta_key = (string) $match['provider']['meta']['id'];
		if ( '' !== trim( (string) $order->get_meta( $meta_key ) ) ) {
			return;
		}
		// meta 還沒寫進去時再看一次 session —— 各家 save_to_order 的時機略有差異
		if ( '' !== self::session_store_id( $match['provider'] ) ) {
			return;
		}

		$message = self::message( $match['provider'] );
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'moksafowo_cvs_no_store',
				esc_html( $message ),
				400
			);
		}
		throw new \Exception( esc_html( $message ) );
	}

	/**
	 * Classic 只有 method id 字串可用，比對出是哪一家的超商取貨。
	 *
	 * @param string[] $chosen Chosen shipping method ids (may carry :instance_id).
	 * @return array{key:string,provider:array<string,mixed>}|null
	 */
	private static function match_chosen( array $chosen ): ?array {
		$providers = CvsStoreEditor::providers();
		foreach ( $chosen as $raw ) {
			$mid = strstr( (string) $raw, ':', true );
			$mid = false === $mid ? (string) $raw : $mid;
			foreach ( $providers as $key => $provider ) {
				if ( isset( $provider['methods'][ $mid ] ) ) {
					return [
						'key'      => (string) $key,
						'provider' => $provider,
					];
				}
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $provider Provider descriptor.
	 */
	private static function session_store_id( array $provider ): string {
		if ( ! isset( $provider['session_store_id'] ) || ! is_callable( $provider['session_store_id'] ) ) {
			return '';
		}
		return trim( (string) call_user_func( $provider['session_store_id'] ) );
	}

	/**
	 * @param array<string,mixed> $provider Provider descriptor.
	 */
	private static function message( array $provider ): string {
		$label = (string) ( $provider['label'] ?? '' );
		if ( '' === $label ) {
			return __( 'Please choose a pickup store before placing the order.', 'moksa-for-woocommerce' );
		}
		return sprintf(
			/* translators: %s: shipping method name, e.g. ECPay convenience store pickup */
			__( 'You chose %s, so please pick the store to collect from before placing the order.', 'moksa-for-woocommerce' ),
			$label
		);
	}
}
