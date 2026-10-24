<?php

declare( strict_types=1 );

namespace Moksafowo\Compatibility;

use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta as PayuniShippingMeta;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions 相容層。
 *
 * WCS 建訂閱、建續訂單時會把來源訂單的 meta 整包複製過去。本外掛的 meta 大多是
 * 「這一張訂單」的結果（交易序號、發票號碼、物流單號），被帶到續訂單上會讓發票
 * 以為已開立、物流以為已建單、退款退到上一期。所以預設全部擋掉，只放行顧客在
 * 結帳時的選擇（發票載具、取貨門市）以及各金流登記的續扣憑證。
 */
final class Subscriptions {

	private const MEMBER_ID_USER_META = '_moksafowo_payment_member_id';

	private const KEEP = [
		Keys::INVOICE_TYPE,
		Keys::INVOICE_BUYER_UBN,
		Keys::INVOICE_BUYER_NAME,
		Keys::INVOICE_CARRIER_TYPE,
		Keys::INVOICE_CARRIER_NUM,
		Keys::INVOICE_LOVE_CODE,
		Keys::INVOICE_PROVIDER,
		Keys::SHIPPING_CVS_STORE_ID,
		Keys::SHIPPING_CVS_STORE_NAME,
		Keys::SHIPPING_CVS_STORE_ADDRESS,
		Keys::SHIPPING_CVS_STORE_PROVIDER,
		Keys::NEWEBPAY_SHIPPING_STORE_ID,
		Keys::NEWEBPAY_SHIPPING_STORE_NAME,
		Keys::NEWEBPAY_SHIPPING_STORE_ADDR,
		Keys::NEWEBPAY_SHIPPING_LGS_TYPE,
		Keys::NEWEBPAY_SHIPPING_SHIP_TYPE,
		Keys::SMILEPAY_SHIPPING_STORE_ID,
		Keys::SMILEPAY_SHIPPING_STORE_NAME,
		Keys::SMILEPAY_SHIPPING_STORE_ADDR,
		PayuniShippingMeta::StoreId,
		PayuniShippingMeta::StoreName,
		PayuniShippingMeta::StoreAddr,
		PayuniShippingMeta::STORE_DATA_JSON,
		PayuniShippingMeta::LgsType,
		PayuniShippingMeta::ShipType,
		PayuniShippingMeta::GoodsType,
	];

	public static function active(): bool {
		return class_exists( 'WC_Subscriptions' ) && function_exists( 'wcs_get_subscriptions_for_order' );
	}

	public static function init(): void {
		if ( ! self::active() ) {
			return;
		}
		add_filter( 'wc_subscriptions_object_data', [ self::class, 'filter_copied_meta' ], 10, 4 );
	}

	public static function filter_copied_meta( $data, $to, $from, $copy_type ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		/**
		 * 各金流把自己的續扣憑證 meta key 加進來，才會被帶到訂閱與續訂單。
		 *
		 * @param string[] $keys
		 */
		$keep = array_merge( self::KEEP, (array) apply_filters( 'moksafowo_subscription_copied_meta_keys', [] ) );
		foreach ( array_keys( $data ) as $key ) {
			if ( str_starts_with( (string) $key, '_moksafowo_' ) && ! in_array( $key, $keep, true ) ) {
				unset( $data[ $key ] );
			}
		}
		return $data;
	}

	/** 訂單是否帶訂閱（首購、續訂、重新訂閱、方案切換都算）。 */
	public static function order_has_subscription( \WC_Order $order ): bool {
		if ( ! self::active() ) {
			return false;
		}
		if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order ) ) {
			return true;
		}
		return function_exists( 'wcs_order_contains_subscription' )
			&& wcs_order_contains_subscription( $order, [ 'parent', 'renewal', 'resubscribe', 'switch' ] );
	}

	/**
	 * 訂單對應的訂閱。傳入的本身就是訂閱（變更付款方式）時回傳它自己。
	 */
	public static function subscriptions_for( \WC_Order $order ): array {
		if ( ! self::active() ) {
			return [];
		}
		if ( wcs_is_subscription( $order ) ) {
			return [ $order ];
		}
		return array_values( wcs_get_subscriptions_for_order( $order, [ 'order_type' => 'any' ] ) );
	}

	/**
	 * 這次 process_payment 是不是顧客在「變更付款方式」（只綁卡、不扣款）。
	 *
	 * 只看傳進來的是不是訂閱：WCS 只有在變更付款方式時才把訂閱 ID 交給 process_payment。
	 * 不能看 WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment，
	 * 那個旗標只要網址帶 change_payment_method 就會被設起來，一般結帳也能被灌成 true。
	 */
	public static function is_changing_payment_method( \WC_Order $order ): bool {
		return self::active() && wcs_is_subscription( $order );
	}

	/**
	 * 給金流商的會員識別碼（綠界 MerchantMemberID、PAYUNi CreditToken、Shopline referenceCustomerId）。
	 *
	 * 不能直接用 WP 使用者編號：同一組金流商帳號給兩個站用（或測試站複製正式站）時，
	 * 兩站的同號會員會對到金流商那邊的同一個會員，看得到、扣得到對方綁的卡。
	 * 每位會員第一次用到時產生一組隨機值存在 user meta，之後固定沿用。沒有會員帳號時回空字串。
	 */
	public static function member_id( \WC_Order $order ): string {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return '';
		}
		$existing = (string) get_user_meta( $user_id, self::MEMBER_ID_USER_META, true );
		if ( preg_match( '/^mowp[0-9a-f]{24}$/', $existing ) ) {
			return $existing;
		}
		$id = 'mowp' . bin2hex( random_bytes( 12 ) );
		update_user_meta( $user_id, self::MEMBER_ID_USER_META, $id );
		return $id;
	}

	/**
	 * 把續扣憑證寫到訂單本身與它所有的訂閱上。續訂單建立時 WCS 會再從訂閱複製過去。
	 *
	 * 訂閱原本的憑證被換掉時觸發 moksafowo_subscription_credentials_replaced，
	 * 讓各金流在舊憑證已沒有其他訂閱使用時向金流商撤銷。
	 */
	public static function store_payment_meta( \WC_Order $order, array $meta ): void {
		$targets = [ $order->get_id() => $order ];
		foreach ( self::subscriptions_for( $order ) as $subscription ) {
			$targets[ $subscription->get_id() ] = $subscription;
		}
		foreach ( $targets as $target ) {
			$old = [];
			if ( self::active() && wcs_is_subscription( $target ) ) {
				foreach ( $meta as $key => $value ) {
					$previous = (string) $target->get_meta( $key );
					if ( '' !== $previous && $previous !== (string) $value ) {
						$old[ $key ] = $previous;
					}
				}
			}
			foreach ( $meta as $key => $value ) {
				$target->update_meta_data( $key, $value );
			}
			$target->save();
			if ( $old ) {
				/**
				 * 訂閱的續扣憑證被換掉了。
				 *
				 * @param \WC_Order            $target 訂閱。
				 * @param array<string,string> $old    被換掉的舊值（meta key => 值）。
				 */
				do_action( 'moksafowo_subscription_credentials_replaced', $target, $old );
			}
		}
	}

	/** 這個憑證是不是還有其他未結束的訂閱在用（撤銷前檢查，同一張卡可能被多個訂閱共用）。 */
	public static function credential_in_use( string $meta_key, string $value, int $exclude_id = 0 ): bool {
		if ( ! self::active() || '' === $value ) {
			return false;
		}
		$ids = wc_get_orders(
			[
				'type'       => 'shop_subscription',
				'status'     => [ 'wc-active', 'wc-on-hold', 'wc-pending', 'wc-pending-cancel' ],
				'limit'      => 5,
				'return'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 撤銷前的一次性查詢；HPOS meta 表有 meta_key 索引。
				'meta_query' => [
					[
						'key'   => $meta_key,
						'value' => $value,
					],
				],
			]
		);
		foreach ( (array) $ids as $id ) {
			if ( (int) $id !== $exclude_id ) {
				return true;
			}
		}
		return false;
	}

	/** 這個訂閱是不是已經結束（取消、到期），結束後才能撤銷它的憑證。 */
	public static function is_ended( \WC_Order $subscription ): bool {
		return $subscription->has_status( [ 'cancelled', 'expired', 'trash' ] );
	}

	/**
	 * 同一張訂單的付款結果可能同時從幕前導回、幕後通知、建立交易回應三個方向進來。
	 * 用 add_option（資料表唯一鍵）當互斥鎖，搶不到的那一個直接略過，由先到的處理。
	 *
	 * add_option 底層是 INSERT … ON DUPLICATE KEY UPDATE：值不同時「更新」也算成功，兩邊都會以為拿到鎖。
	 * 所以鎖的值固定為 '1'（重複時影響 0 列 → 回傳 false），取得時間另存一個 option 判斷是否逾時。
	 */
	public static function lock( string $name, int $ttl = 60 ): bool {
		$option = 'moksafowo_lock_' . md5( $name );
		if ( add_option( $option, '1', '', false ) ) {
			update_option( $option . '_t', (string) time(), false );
			return true;
		}
		$since = (int) get_option( $option . '_t', 0 );
		if ( time() - $since > $ttl ) {
			// 上一個持有者當掉沒解鎖：清掉重搶，仍只有一個請求的 add_option 會成功。
			delete_option( $option );
			if ( add_option( $option, '1', '', false ) ) {
				update_option( $option . '_t', (string) time(), false );
				return true;
			}
		}
		return false;
	}

	public static function unlock( string $name ): void {
		$option = 'moksafowo_lock_' . md5( $name );
		delete_option( $option );
		delete_option( $option . '_t' );
	}
}
