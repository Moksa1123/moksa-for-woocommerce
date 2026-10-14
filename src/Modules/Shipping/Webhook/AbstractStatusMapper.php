<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Webhook;

defined( 'ABSPATH' ) || exit;

abstract class AbstractStatusMapper {

	abstract protected function provider_slug(): string;

	abstract protected function code_map(): array;

	/** 出貨進度的先後；不在表內的（異常、未取、關轉）一律照通知處理。 */
	private const PROGRESS = [
		'moksa-shipped'     => 1,
		'moksa-cvs-arrived' => 2,
		'completed'         => 3,
	];

	/**
	 * 每一包目前的貨態代碼（依溫層拆單時一包一筆）。回傳空陣列表示單一包裹。
	 *
	 * @return string[]
	 */
	protected function package_codes( \WC_Order $order ): array {
		return [];
	}

	/**
	 * 拆成多包時，整張訂單只走到最慢那一包的進度：任何一包取貨就標「已完成」，
	 * 貨到付款的其他包還沒收錢。也不讓進度倒退（已完成 → 已出貨）。
	 */
	private function aggregate_slug( \WC_Order $order, ?string $slug ): ?string {
		if ( null === $slug || ! isset( self::PROGRESS[ $slug ] ) ) {
			return $slug;
		}
		$codes = $this->package_codes( $order );
		if ( count( $codes ) > 1 ) {
			$min = $slug;
			foreach ( $codes as $code ) {
				$pkg_slug = $this->code_map()[ (string) $code ] ?? null;
				$pkg_slug = apply_filters( 'moksafowo_shipping_lgs_status_map', $pkg_slug, (string) $code, $order, $this->provider_slug() );
				if ( ! is_string( $pkg_slug ) || ! isset( self::PROGRESS[ $pkg_slug ] ) ) {
					// 有一包還沒任何進度（或停在異常），整單不往前推。
					return null;
				}
				if ( self::PROGRESS[ $pkg_slug ] < self::PROGRESS[ $min ] ) {
					$min = $pkg_slug;
				}
			}
			$slug = $min;
		}
		$current = $order->get_status();
		if ( isset( self::PROGRESS[ $current ] ) && self::PROGRESS[ $current ] > self::PROGRESS[ $slug ] ) {
			return null;
		}
		return $slug;
	}

	public function handle_status_received( \WC_Order $order, string $code, string $desc = '' ): void {
		$provider = $this->provider_slug();

		do_action( "moksafowo_shipping_status_received_{$provider}_{$code}", $order, $desc );
		do_action( 'moksafowo_shipping_status_received', $order, $code, $desc, $provider );

		$slug = $this->code_map()[ $code ] ?? null;
		$slug = apply_filters( 'moksafowo_shipping_lgs_status_map', $slug, $code, $order, $provider );

		$slug = $this->aggregate_slug( $order, $slug );

		if ( null === $slug || $order->get_status() === $slug ) {
			return;
		}

		$provider_label_map = [
			'ecpay'  => __( 'ECPay', 'moksa-for-woocommerce' ),
			'payuni' => __( 'PAYUNi', 'moksa-for-woocommerce' ),
		];
		$provider_label     = $provider_label_map[ $provider ] ?? strtoupper( $provider );
		$note               = sprintf(
			/* translators: 1: provider label, 2: description, 3: code */
			__( '%1$s shipping status: %2$s (status code %3$s)', 'moksa-for-woocommerce' ),
			$provider_label,
			$desc,
			$code
		);
		// 目標狀態被商家關掉時只記備註；硬改的話 WC 會把訂單改成「等待付款中」。
		if ( ! \Moksafowo\Modules\Shipping\Statuses\Registrar::is_usable( $slug ) ) {
			$order->add_order_note( $note );
			return;
		}
		$order->update_status( $slug, $note );

		do_action( "moksafowo_shipping_status_changed_{$slug}", $order, $provider, $code );

		// moksa-* 自訂狀態走自家 email；WC 既有狀態走 WC 內建 email pipeline。
		// 前綴曾是 mo-，改名成 moksa- 後這裡漏改，導致三封物流信從未觸發。
		if ( str_starts_with( $slug, 'moksa-' ) ) {
			do_action( 'moksafowo_shipping_status_' . $slug . '_notification', $order->get_id(), $order );
		}
	}
}
