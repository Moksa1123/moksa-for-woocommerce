<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Webhook;

defined( 'ABSPATH' ) || exit;

abstract class AbstractStatusMapper {

	abstract protected function provider_slug(): string;

	abstract protected function code_map(): array;

	/**
	 * 這次請求裡 mapper 已經替哪些訂單寫過貨態備註（呼叫端據此決定要不要自己補一則）。
	 *
	 * @var array<int, bool>
	 */
	private static array $noted = [];

	/** mapper 剛才有沒有替這張訂單寫貨態備註；有的話呼叫端不要再寫，一次轉換只留一則。 */
	public static function wrote_note( \WC_Order $order ): bool {
		return isset( self::$noted[ $order->get_id() ] );
	}

	/** 出貨進度的先後；不在表內的（異常、未取、關轉）一律照通知處理。 */
	private const PROGRESS = [
		'moksa-shipped'     => 1,
		'moksa-cvs-arrived' => 2,
		'completed'         => 3,
	];

	/**
	 * 「包裹已回到賣家手上」的貨態代碼（賣家已取回退貨、已退回寄件人）。
	 *
	 * @return string[]
	 */
	protected function returned_to_seller_codes(): array {
		return [];
	}

	/**
	 * 包裹已回到賣家手上：
	 * - 取貨付款、還沒收到錢 → 訂單改「已取消」（WC 取消時自動回補庫存）。
	 * - 已付款 → 狀態照對應表（未取件），另留一次備註請店家決定退款或重寄。不自動退款。
	 *
	 * @return bool true＝已處理完（取消），不再照對應表改狀態。
	 */
	private function handle_returned_to_seller( \WC_Order $order, string $code ): bool {
		if ( \Moksafowo\Modules\Shipping\Statuses\StatusGuard::is_terminal( $order->get_status() ) ) {
			return false;
		}
		// 依溫層拆成多包時，要每一包都退回賣家才整張取消；有一包送達就只留備註。
		$packages     = $this->package_codes( $order );
		$all_returned = count( $packages ) <= 1 || ! array_diff( $packages, $this->returned_to_seller_codes() );
		// 不看 date_paid：WC 的貨到付款一轉處理中就會填付款日期。取貨付款的包裹退回賣家＝客人沒取、也沒付。
		if ( $all_returned && \Moksafowo\Modules\Shared\Invoice\PayOnDelivery::is_pay_on_delivery( $order ) ) {
			self::$noted[ $order->get_id() ] = true;
			$order->update_status(
				'cancelled',
				sprintf(
					/* translators: %s: shipping status code */
					__( 'The parcel was not collected and is back with the seller (status code %s). This pay-on-pickup order was never paid, so it was cancelled and the stock was restored.', 'moksa-for-woocommerce' ),
					$code
				)
			);
			return true;
		}
		if ( 'yes' !== $order->get_meta( '_moksafowo_returned_to_seller_noted' ) ) {
			$order->update_meta_data( '_moksafowo_returned_to_seller_noted', 'yes' );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: %s: shipping status code */
					__( 'The parcel is back with the seller (status code %s). This order was already paid: please contact the customer and either refund it (tick "Restock refunded items") or ship it again.', 'moksa-for-woocommerce' ),
					$code
				)
			);
		}
		return false;
	}

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
		if ( \Moksafowo\Modules\Shipping\Statuses\StatusGuard::is_regression( $order->get_status(), $slug ) ) {
			return null;
		}
		return $slug;
	}

	public function handle_status_received( \WC_Order $order, string $code, string $desc = '' ): void {
		$provider = $this->provider_slug();
		unset( self::$noted[ $order->get_id() ] );

		do_action( "moksafowo_shipping_status_received_{$provider}_{$code}", $order, $desc );
		do_action( 'moksafowo_shipping_status_received', $order, $code, $desc, $provider );

		if ( in_array( $code, $this->returned_to_seller_codes(), true ) && $this->handle_returned_to_seller( $order, $code ) ) {
			return;
		}

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
		// 補查與手動查詢拿不到說明文字時，用目標狀態的名稱，不要印出空白。
		$note = \Moksafowo\Order\Note::build(
			/* translators: %s: provider label */
			sprintf( __( '%s shipping status update', 'moksa-for-woocommerce' ), $provider_label ),
			[
				__( 'Shipping status', 'moksa-for-woocommerce' ) => '' !== $desc ? $desc : wc_get_order_status_name( $slug ),
				__( 'Status code', 'moksa-for-woocommerce' )     => $code,
			]
		);
		self::$noted[ $order->get_id() ] = true;
		// 目標狀態被商家關掉時只記備註；硬改的話 WC 會把訂單改成「等待付款中」。
		// 已退款、已取消、失敗的訂單只能人工改，物流通知也只記備註。
		if ( ! \Moksafowo\Modules\Shipping\Statuses\Registrar::is_usable( $slug )
			|| \Moksafowo\Modules\Shipping\Statuses\StatusGuard::is_terminal( $order->get_status() ) ) {
			$order->add_order_note( $note );
			return;
		}
		$order->update_status( $slug, $note );

		do_action( "moksafowo_shipping_status_changed_{$slug}", $order, $provider, $code );

		// moksa-* 自訂狀態走自家 email；WC 既有狀態走 WC 內建 email pipeline。
		if ( str_starts_with( $slug, 'moksa-' ) ) {
			do_action( 'moksafowo_shipping_status_' . $slug . '_notification', $order->get_id(), $order );
		}
	}
}
