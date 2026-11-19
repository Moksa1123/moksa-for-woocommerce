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
		add_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', [ self::class, 'delay_payment_method_change' ], 10, 3 );
	}

	/**
	 * 顧客在「變更付款方式」選了我們的信用卡：WCS 預設在綁卡「之前」就把訂閱改成新付款方式，
	 * 綁卡失敗或顧客中途離開，訂閱會變成新付款方式卻沒有卡、續扣必定失敗。
	 * 改成綁卡成功後由 finish_payment_method_change() 才切換。
	 *
	 * @param bool   $update             WCS 預設 true。
	 * @param string $new_payment_method 新付款方式 ID。
	 * @return bool
	 */
	public static function delay_payment_method_change( $update, $new_payment_method, $subscription = null ) {
		/**
		 * 綁卡成功才切換付款方式的金流（要自己呼叫 finish_payment_method_change）。
		 *
		 * @param string[] $gateway_ids
		 */
		$delayed = (array) apply_filters( 'moksafowo_subscription_delayed_change_gateways', [] );
		return in_array( (string) $new_payment_method, $delayed, true ) ? false : $update;
	}

	/**
	 * WCS 送出「變更付款方式」後就加上「付款方式已更新」，但綠界、SLP 換卡要等顧客在下一頁輸入卡號、
	 * 驗卡成功才算。帶到換卡表單時拿掉那則訊息，免得顧客以為已經換好。
	 * 這一步唯一的成功訊息就是 WCS 那則（不用 WCS 的翻譯字串比對：外掛不能呼叫別的 text domain）。
	 */
	public static function drop_premature_change_notice(): void {
		$on_form = '1' === \Moksafowo\Http\Input::get( 'moksafowo_ecpay_card_change' ) || '1' === \Moksafowo\Http\Input::get( 'moksafowo_slp_card_change' );
		if ( ! $on_form || ! function_exists( 'wc_get_notices' ) || ! WC()->session ) {
			return;
		}
		$notices = wc_get_notices();
		if ( empty( $notices['success'] ) ) {
			return;
		}
		$notices['success'] = [];
		wc_set_notices( $notices );
	}

	/** 綁卡成功：把訂閱的付款方式切到這個金流（續扣憑證要先用 store_payment_meta 寫好）。 */
	public static function finish_payment_method_change( \WC_Order $subscription, string $gateway_id ): void {
		if ( ! self::active() || ! wcs_is_subscription( $subscription ) || ! class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
			return;
		}
		$fresh = wcs_get_subscription( $subscription->get_id() );
		if ( ! $fresh ) {
			return;
		}
		if ( $fresh->get_payment_method() === $gateway_id ) {
			return; // 同一個付款方式只是換卡，不用切換（WCS 會多留一則「從 X 變更為 X」）。
		}
		\WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $fresh, $gateway_id );
		// 綠界通知、SLP webhook 不在結帳頁，WCS 查不到可用付款方式時會把標題寫成 ID，補成正式名稱。
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
		if ( isset( $gateways[ $gateway_id ] ) ) {
			$fresh->set_payment_method_title( $gateways[ $gateway_id ]->get_title() );
		}
		$fresh->save();
	}

	/**
	 * 換卡結果回到訂閱頁時顯示訊息。綠界的回傳（OrderResultURL、建立交易回應）不在顧客的 WC session 裡，
	 * 訊息加不進去，所以改帶網址參數，回到前台頁面再加。
	 */
	public static function card_change_result_notice(): void {
		$result = \Moksafowo\Http\Input::get( 'moksafowo_card_change_result' );
		if ( '' === $result || ! function_exists( 'wc_add_notice' ) || ! WC()->session ) {
			return;
		}
		if ( 'done' === $result ) {
			wc_add_notice( __( 'Your new card was saved. Your subscription will renew with it.', 'moksa-for-woocommerce' ) );
		} elseif ( 'pending' === $result ) {
			wc_add_notice( __( 'ECPay is still confirming the new card. Check this page again in a few minutes.', 'moksa-for-woocommerce' ), 'notice' );
		} else {
			wc_add_notice( __( 'The new card was not saved, so your subscription keeps using the current payment method. Please try again or contact the store.', 'moksa-for-woocommerce' ), 'error' );
		}
		wp_safe_redirect( remove_query_arg( 'moksafowo_card_change_result' ) );
		exit;
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
		$ids = \Moksafowo\Order\Lookup::orders(
			[
				'type'                             => 'shop_subscription',
				'status'                           => [ 'wc-active', 'wc-on-hold', 'wc-pending', 'wc-pending-cancel' ],
				'limit'                            => 5,
				'return'                           => 'ids',
				\Moksafowo\Order\Lookup::QUERY_VAR => [
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
		if ( 0 === $since ) {
			// 剛被別人拿到、取得時間還沒寫入：不能當成逾時搶走（同時送很多請求會兩邊都拿到）。
			// 補寫時間，萬一持有者在這之間當掉，鎖仍會照 TTL 逾時。
			add_option( $option . '_t', (string) time(), '', false );
			return false;
		}
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

	/**
	 * 顧客自行換卡的次數上限：每位會員每個金流 24 小時內最多 $per_day 次（成功、失敗都算）。
	 * 換卡會向發卡行驗卡，不限次數就能被拿來試盜來的卡號。
	 * 每一次佔一個名額鎖（原子），24 小時後自動釋出。
	 */
	public static function take_card_change_slot( int $user_id, string $gateway, int $per_day = 3 ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		for ( $n = 1; $n <= $per_day; $n++ ) {
			if ( self::lock( 'card-change-' . $gateway . '-' . $user_id . '-' . $n, DAY_IN_SECONDS ) ) {
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
