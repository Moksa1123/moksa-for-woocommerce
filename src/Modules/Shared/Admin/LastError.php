<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * 開發票／建物流單最後一次失敗的原因，讓訂單列表不用點進訂單就看得到。
 */
final class LastError {

	/**
	 * @param \WC_Order           $order    訂單。
	 * @param string              $meta_key 存放失敗原因的 meta key。
	 * @param string              $provider 金流／物流／發票商代號。
	 * @param array<string,mixed> $result   各家 run() 的回傳，至少有 ok、message。
	 * @return array<string,mixed> 原樣回傳，方便呼叫端直接接著用。
	 */
	public static function record( \WC_Order $order, string $meta_key, string $provider, array $result ): array {
		$fresh = wc_get_order( $order->get_id() );
		if ( ! $fresh instanceof \WC_Order ) {
			return $result;
		}
		if ( ! empty( $result['ok'] ) ) {
			if ( '' !== (string) $fresh->get_meta( $meta_key ) ) {
				$fresh->delete_meta_data( $meta_key );
				$fresh->save();
			}
			return $result;
		}
		$message = trim( (string) ( $result['message'] ?? '' ) );
		$fresh->update_meta_data(
			$meta_key,
			[
				'provider' => $provider,
				'message'  => '' !== $message ? $message : __( 'Unknown error', 'moksa-for-woocommerce' ),
				'time'     => time(),
			]
		);
		$fresh->save();
		return $result;
	}

	/**
	 * @return array{provider: string, message: string, time: int}|null
	 */
	public static function get( \WC_Order $order, string $meta_key ): ?array {
		$raw = $order->get_meta( $meta_key );
		if ( ! is_array( $raw ) || '' === (string) ( $raw['message'] ?? '' ) ) {
			return null;
		}
		return [
			'provider' => (string) ( $raw['provider'] ?? '' ),
			'message'  => (string) $raw['message'],
			'time'     => (int) ( $raw['time'] ?? 0 ),
		];
	}
}
