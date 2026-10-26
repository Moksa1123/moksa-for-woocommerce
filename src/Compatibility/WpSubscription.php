<?php

declare( strict_types=1 );

namespace Moksafowo\Compatibility;

use Moksafowo\Order\Meta\Keys;
use SpringDevs\Subscription\Illuminate\Action;
use SpringDevs\Subscription\Illuminate\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * WPSubscription（ConversWP）相容層。
 *
 * 訂閱是 subscrpt_order 文章，訂單與訂閱的對應在 subscrpt_order_relation 表。它的續訂流程：
 * 每小時排程發現到期 → 訂閱轉「已過期」→ 從上一張「已完成」訂單複製一張待付款續訂單 →
 * subscrpt_after_create_renew_order 讓金流扣款 → 續訂單「已完成」才重新啟用訂閱。
 *
 * 這裡補上三件它沒做的事：
 * - 續訂單不帶付款方式：依訂閱記錄的付款方式補上，並帶入各金流登記的續扣憑證。
 * - 付款成功後要等商家把訂單標成完成，訂閱才會恢復（期間顧客會收到「訂閱已過期」）：
 *   續訂單一付款就直接啟用訂閱，訂單照常留在處理中等出貨。
 * - 上一張訂單沒完成時它不建續訂單也不扣款：改從最近一張已付款訂單自己建。
 *
 * 金流接法：付款成功時呼叫 store_payment_meta() 記下憑證與付款方式，
 * 再掛 moksafowo_wps_scheduled_payment_{gateway_id} 處理扣款。
 */
final class WpSubscription {

	/**
	 * 這次請求裡建過續訂單的訂閱。
	 *
	 * @var array<int,bool>
	 */
	private static array $created = [];

	public static function active(): bool {
		return class_exists( Helper::class ) && class_exists( Action::class );
	}

	public static function init(): void {
		if ( ! self::active() ) {
			return;
		}
		add_filter( 'subscrpt_before_saving_renewal_order', [ self::class, 'prepare_renewal' ], 10, 3 );
		add_action( 'subscrpt_after_create_renew_order', [ self::class, 'dispatch' ], 20, 4 );
		add_action( 'subscrpt_subscription_expired', [ self::class, 'ensure_renewal' ], 30 );
		add_action( 'woocommerce_payment_complete', [ self::class, 'activate_on_payment' ], 20 );
		add_filter( 'subscrpt_create_retry_renewal_order', [ self::class, 'retry_renewal' ], 10, 2 );
	}

	/**
	 * 訂單建立或續訂的訂閱編號。
	 *
	 * @param int      $order_id 訂單編號。
	 * @param string[] $types    relation 的 type：new、renew、early-renew。
	 * @return int[]
	 */
	public static function subscription_ids( int $order_id, array $types = [ 'new' ] ): array {
		if ( ! self::active() || $order_id <= 0 ) {
			return [];
		}
		$ids = [];
		foreach ( (array) Helper::get_subscriptions_from_order( $order_id ) as $row ) {
			if ( in_array( (string) ( $row->type ?? '' ), $types, true ) ) {
				$ids[] = (int) $row->subscription_id;
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public static function order_has_subscription( \WC_Order $order ): bool {
		return [] !== self::subscription_ids( $order->get_id(), [ 'new', 'renew', 'early-renew' ] );
	}

	public static function gateway_of( int $subscription_id ): string {
		return (string) get_post_meta( $subscription_id, Keys::WPS_GATEWAY, true );
	}

	public static function status( int $subscription_id ): string {
		return (string) get_post_status( $subscription_id );
	}

	public static function set_status( int $subscription_id, string $status ): void {
		Action::status( $status, $subscription_id );
	}

	/**
	 * 訂閱的計費週期，取自訂閱品項的 _subscrpt_meta（type 是 day(s)／week(s)／month(s)／year(s)）。
	 *
	 * @return array{interval:int,period:string,trial:bool}|null
	 */
	public static function schedule( int $subscription_id ): ?array {
		$meta = wc_get_order_item_meta( (int) get_post_meta( $subscription_id, '_subscrpt_order_item_id', true ), '_subscrpt_meta', true );
		if ( ! is_array( $meta ) || empty( $meta['type'] ) ) {
			return null;
		}
		return [
			'interval' => max( 1, (int) ( $meta['time'] ?? 1 ) ),
			'period'   => rtrim( strtolower( (string) $meta['type'] ), 's' ),
			'trial'    => ! empty( $meta['trial'] ),
		];
	}

	/** 分期或限制期數的訂閱總共付幾次；0 代表不限。 */
	public static function max_payments( int $subscription_id ): int {
		return function_exists( 'subscrpt_get_max_payments' ) ? (int) subscrpt_get_max_payments( $subscription_id ) : 0;
	}

	/**
	 * 購物車的訂閱品項（WPSubscription 自己的判斷），補上週期。
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function cart_recurrings(): array {
		$cart = self::active() && function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart ) {
			return [];
		}
		$items   = $cart->get_cart();
		$recurrs = (array) Helper::get_recurrs_from_cart( $items );
		foreach ( $recurrs as $key => $recurr ) {
			$sub                     = (array) ( $items[ $key ]['subscription'] ?? [] );
			$recurrs[ $key ]['time'] = max( 1, (int) ( $sub['time'] ?? 1 ) );
			$recurrs[ $key ]['type'] = rtrim( strtolower( (string) ( $sub['type'] ?? '' ) ), 's' );
		}
		return $recurrs;
	}

	/** 寫一筆訂閱活動紀錄（訂閱頁的「活動」）。 */
	public static function note( int $subscription_id, string $text ): void {
		$comment_id = wp_insert_comment(
			[
				'comment_author'  => 'Moksa for WooCommerce',
				'comment_content' => $text,
				'comment_post_ID' => $subscription_id,
				'comment_type'    => 'order_note',
			]
		);
		if ( $comment_id ) {
			update_comment_meta( $comment_id, '_subscrpt_activity', 'Payment' );
			update_comment_meta( $comment_id, '_subscrpt_activity_type', 'moksafowo_payment' );
		}
	}

	public static function parent_order( int $subscription_id ): ?\WC_Order {
		foreach ( Helper::get_related_orders( $subscription_id ) as $row ) {
			if ( 'new' === (string) ( $row->type ?? '' ) ) {
				$order = wc_get_order( (int) $row->order_id );
				return $order instanceof \WC_Order ? $order : null;
			}
		}
		return null;
	}

	/**
	 * 付款成功時：把續扣憑證寫到訂單與它建立的訂閱，並記下訂閱改由哪個付款方式續扣。
	 *
	 * @param \WC_Order             $order      付款完成的訂單。
	 * @param string               $gateway_id 付款方式 ID。
	 * @param array<string,string> $meta       續扣憑證（meta key => 值）。
	 */
	public static function store_payment_meta( \WC_Order $order, string $gateway_id, array $meta ): void {
		foreach ( self::subscription_ids( $order->get_id(), [ 'new', 'renew', 'early-renew' ] ) as $subscription_id ) {
			$old = [];
			foreach ( $meta as $key => $value ) {
				$previous = (string) get_post_meta( $subscription_id, $key, true );
				if ( '' !== $previous && $previous !== (string) $value ) {
					$old[ $key ] = $previous;
				}
				update_post_meta( $subscription_id, $key, $value );
			}
			update_post_meta( $subscription_id, Keys::WPS_GATEWAY, $gateway_id );
			if ( $old ) {
				/**
				 * 訂閱的續扣憑證被換掉了。
				 *
				 * @param int                  $subscription_id WPSubscription 訂閱編號。
				 * @param array<string,string> $old             被換掉的舊值。
				 */
				do_action( 'moksafowo_wps_credentials_replaced', $subscription_id, $old );
			}
		}
	}

	/** 這個憑證是不是還有其他未結束的 WPSubscription 訂閱在用。 */
	public static function credential_in_use( string $meta_key, string $value, int $exclude_id = 0 ): bool {
		if ( ! self::active() || '' === $value ) {
			return false;
		}
		$ids = get_posts(
			[
				'post_type'      => 'subscrpt_order',
				'post_status'    => [ 'active', 'pending', 'pe_cancelled', 'expired', 'on-hold' ],
				'posts_per_page' => 5,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 撤銷前的一次性查詢。
				'meta_query'     => [
					[
						'key'   => $meta_key,
						'value' => $value,
					],
				],
			]
		);
		foreach ( $ids as $id ) {
			if ( (int) $id !== $exclude_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 續訂單補上付款方式與續扣憑證（WPSubscription 建單時兩者都不帶）。
	 *
	 * @param \WC_Order $renewal         尚未儲存的續訂單。
	 * @param \WC_Order $source          複製來源訂單。
	 * @param int       $subscription_id WPSubscription 訂閱編號。
	 */
	public static function prepare_renewal( $renewal, $source, $subscription_id ) {
		$gateway_id = self::gateway_of( (int) $subscription_id );
		if ( '' === $gateway_id || ! $renewal instanceof \WC_Order ) {
			return $renewal;
		}
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
		$renewal->set_payment_method( $gateway_id );
		$renewal->set_payment_method_title( isset( $gateways[ $gateway_id ] ) ? $gateways[ $gateway_id ]->get_title() : $gateway_id );
		/** 與 WooCommerce Subscriptions 共用：各金流登記的續扣憑證 meta key。 */
		foreach ( (array) apply_filters( 'moksafowo_subscription_copied_meta_keys', [] ) as $key ) {
			$value = get_post_meta( (int) $subscription_id, (string) $key, true );
			if ( '' !== (string) $value ) {
				$renewal->update_meta_data( (string) $key, $value );
			}
		}
		return $renewal;
	}

	/**
	 * @param \WC_Order $renewal         續訂單。
	 * @param \WC_Order $source          複製來源訂單。
	 * @param int       $subscription_id WPSubscription 訂閱編號。
	 * @param bool      $early           顧客自己按「提前續訂」建立的，顧客會在付款頁自己付。
	 */
	public static function dispatch( $renewal, $source, $subscription_id, $early = false ): void {
		self::$created[ (int) $subscription_id ] = true;
		if ( ! $renewal instanceof \WC_Order || $early ) {
			return;
		}
		$renewal = wc_get_order( $renewal->get_id() );
		// 已被其他規則（例如 Pro 的續訂次數上限）取消就不扣款。
		if ( ! $renewal instanceof \WC_Order || ! $renewal->needs_payment() || '' === $renewal->get_payment_method() ) {
			return;
		}
		/**
		 * WPSubscription 到期建立了待付款的續訂單，由對應金流扣款。
		 *
		 * @param \WC_Order $renewal         續訂單。
		 * @param int       $subscription_id WPSubscription 訂閱編號。
		 */
		do_action( 'moksafowo_wps_scheduled_payment_' . $renewal->get_payment_method(), $renewal, (int) $subscription_id );
	}

	/**
	 * WPSubscription 只從「已完成」的訂單複製續訂單，上一張還在處理中（等出貨）時它不建單也不扣款，
	 * 訂閱就這樣過期。本外掛付款的訂閱改從最近一張已付款訂單自己建。
	 */
	public static function ensure_renewal( $subscription_id ): void {
		$subscription_id = (int) $subscription_id;
		if ( isset( self::$created[ $subscription_id ] ) || '' === self::gateway_of( $subscription_id ) || 'expired' !== self::status( $subscription_id ) ) {
			return;
		}
		if ( ! function_exists( 'subscrpt_is_auto_renew_enabled' ) || ! subscrpt_is_auto_renew_enabled() ) {
			return;
		}
		if ( function_exists( 'subscrpt_is_max_payments_reached' ) && subscrpt_is_max_payments_reached( $subscription_id ) ) {
			return;
		}
		$current = wc_get_order( (int) get_post_meta( $subscription_id, '_subscrpt_order_id', true ) );
		if ( $current instanceof \WC_Order && $current->needs_payment() ) {
			return;
		}
		if ( null === self::create_renewal( $subscription_id ) ) {
			self::note( $subscription_id, __( 'No renewal order could be created because this subscription has no paid order to copy from.', 'moksa-for-woocommerce' ) );
		}
	}

	/**
	 * 自己建一張續訂單，流程照 Helper::create_renewal_order，只是來源改成最近一張「已付款」的訂單。
	 * 金流商已經扣款（藍新照委託排程扣）時也用它留下訂單紀錄。
	 */
	public static function create_renewal( int $subscription_id ): ?\WC_Order {
		$old_order = null;
		$item      = null;
		foreach ( Helper::get_related_orders( $subscription_id ) as $row ) {
			$order = wc_get_order( (int) $row->order_id );
			$found = $order instanceof \WC_Order && $order->is_paid() ? $order->get_item( (int) $row->order_item_id ) : null;
			if ( $found instanceof \WC_Order_Item_Product ) {
				$old_order = $order;
				$item      = $found;
				break;
			}
		}
		if ( null === $old_order || null === $item ) {
			return null;
		}

		$price = (float) get_post_meta( $subscription_id, '_subscrpt_price', true );
		if ( wc_prices_include_tax() ) {
			$product = $item->get_product();
			$rates   = \WC_Tax::get_rates( $product ? $product->get_tax_class() : '' );
			$price  -= array_sum( \WC_Tax::calc_inclusive_tax( $price, $rates ) );
		}
		$line    = $price * $item->get_quantity();
		$created = Helper::create_new_order_for_renewal(
			$old_order,
			$item,
			[
				'name'     => $item->get_name(),
				'subtotal' => $line,
				'total'    => $line,
			]
		);
		if ( empty( $created['order'] ) ) {
			return null;
		}
		$renewal = $created['order'];
		Helper::create_renewal_history( $subscription_id, $renewal->get_id(), (int) $created['order_item_id'] );
		update_post_meta( $subscription_id, '_subscrpt_order_id', $renewal->get_id() );
		update_post_meta( $subscription_id, '_subscrpt_order_item_id', (int) $created['order_item_id'] );
		Helper::clone_order_metadata( $renewal, $old_order );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPSubscription's own hook, fired exactly as its Helper::create_renewal_order() does so its add-ons (coupons, limits) still run.
		$renewal = apply_filters( 'subscrpt_before_saving_renewal_order', $renewal, $old_order, $subscription_id );
		$renewal->calculate_totals();
		$renewal->save();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPSubscription's own hook, fired exactly as its Helper::create_renewal_order() does.
		do_action( 'subscrpt_after_create_renew_order', $renewal, $old_order, $subscription_id, false );
		$renewal = wc_get_order( $renewal->get_id() );
		return $renewal instanceof \WC_Order ? $renewal : null;
	}

	/**
	 * Pro 的扣款重試用 Helper::create_renewal_order 建單，上一張訂單沒完成時建不出來；
	 * 本外掛付款的訂閱改用 create_renewal()。
	 *
	 * @param int|null $override        其他外掛已建好的續訂單編號。
	 * @param int      $subscription_id WPSubscription 訂閱編號。
	 */
	public static function retry_renewal( $override, $subscription_id ) {
		if ( $override || '' === self::gateway_of( (int) $subscription_id ) ) {
			return $override;
		}
		$renewal = self::create_renewal( (int) $subscription_id );
		return $renewal ? $renewal->get_id() : $override;
	}

	/**
	 * 訂閱是不是因為續訂單扣款失敗才被取消。WPSubscription 會把失敗的續訂單連帶取消訂閱，
	 * 但 Pro 的重試成功後會重新啟用，所以這時不能撤銷續扣憑證或終止委託。
	 */
	public static function cancelled_by_failed_payment( int $subscription_id ): bool {
		$order = wc_get_order( (int) get_post_meta( $subscription_id, '_subscrpt_order_id', true ) );
		return $order instanceof \WC_Order && $order->has_status( 'failed' );
	}

	/** 訂閱目前還沒付款的續訂單。 */
	public static function current_unpaid_renewal( int $subscription_id ): ?\WC_Order {
		$order = wc_get_order( (int) get_post_meta( $subscription_id, '_subscrpt_order_id', true ) );
		if ( ! $order instanceof \WC_Order || $order->is_paid() || ! $order->has_status( [ 'pending', 'failed', 'on-hold' ] ) ) {
			return null;
		}
		return in_array( $subscription_id, self::subscription_ids( $order->get_id(), [ 'renew' ] ), true ) ? $order : null;
	}

	/**
	 * 本外掛付款的續訂單付款成功：直接啟用訂閱，不必等商家把訂單標成完成。
	 * 之後商家完成訂單時 WPSubscription 看到狀態沒變就不會再動日期。
	 */
	public static function activate_on_payment( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( self::subscription_ids( $order->get_id(), [ 'renew' ] ) as $subscription_id ) {
			if ( self::gateway_of( $subscription_id ) !== $order->get_payment_method() ) {
				continue;
			}
			$status = self::status( $subscription_id );
			// 扣款失敗會被 WPSubscription 連帶取消；重試或顧客補付成功就該恢復，顧客自己取消的不動。
			$failed = (int) get_post_meta( $subscription_id, '_subscrpt_payment_failure_count', true ) > 0
				|| (int) get_post_meta( $subscription_id, '_subscrpt_order_id', true ) === $order->get_id();
			if ( ! in_array( $status, [ 'expired', 'pending', 'on-hold', 'on_hold' ], true ) && ! ( 'cancelled' === $status && $failed ) ) {
				continue;
			}
			// 次數上限（Pro）原本在續訂單完成時才累加；改在這裡啟用就要在這裡加。
			if ( function_exists( 'subscrpt_pro_activated' ) && subscrpt_pro_activated() && class_exists( '\SpringDevs\SubscriptionPro\Illuminate\LimitChecker' ) ) {
				\SpringDevs\SubscriptionPro\Illuminate\LimitChecker::increment_renewal_count( $subscription_id );
			}
			self::set_status( $subscription_id, 'active' );
		}
	}
}
