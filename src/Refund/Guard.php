<?php

declare( strict_types=1 );

namespace Moksafowo\Refund;

use Moksafowo\Modules\Shared\Caps;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 後台「退款」按鈕前面的一道關卡（設計 docs/退款與發票連動設計.md §四、§五）。
 *
 * WC 的退款 AJAX（woocommerce_refund_line_items，優先序 10）之前先跑這裡：
 * 權限 → 結果未知的退款還沒處理就擋 → 訂單編號末 4 碼 → 一次性 token（雙擊、重送只算一次）
 * → 訂單鎖（同一張單同時只能有一筆退款在跑，WC 自己的「不超過可退金額」才不會被同時的請求繞過）。
 * 退款中若連金流失敗（斷線、逾時、5xx），WC 會撤銷退款紀錄，但金流那邊可能已經退了：
 * 記成「結果未知」並擋住這張單後續的退款，等店長在待處理清單確認。
 */
final class Guard {

	public const AJAX_TOKEN = 'moksafowo_refund_token';

	private const LOCK_TTL  = 300;
	private const TOKEN_TTL = 600;

	private static int $active_order = 0;

	private static bool $succeeded = false;

	private static string $http_problem = '';

	private static bool $api_refund = false;

	private static int $buffer_level = 0;

	private static string $captured = '';

	/** @var array<string,string> 這個請求拿到的鎖：名稱 => 值。 */
	private static array $locks = [];

	private static string $amount = '0';

	/** @var array<int,bool> 這個請求裡金流已自己呼叫 flag() 的訂單（finish() 不再重複記一筆）。 */
	private static array $flagged = [];

	public static function init(): void {
		add_action( 'wp_ajax_woocommerce_refund_line_items', [ __CLASS__, 'before_refund' ], 1 );
		add_action( 'wp_ajax_' . self::AJAX_TOKEN, [ __CLASS__, 'ajax_new_token' ] );
		add_action( 'woocommerce_order_refunded', [ __CLASS__, 'after_refund' ], 5, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'moksafowo_refund_token_cleanup', [ __CLASS__, 'cleanup_token' ] );
		add_action( 'woocommerce_create_refund', [ __CLASS__, 'before_create_refund' ], 1, 2 );
	}

	public static function last4( \WC_Order $order ): string {
		$number = (string) $order->get_order_number();
		return function_exists( 'mb_substr' ) ? mb_substr( $number, -4 ) : substr( $number, -4 );
	}

	/** 結果未知、還沒確認的退款。 */
	public static function unknown_list( \WC_Order $order ): array {
		$list = $order->get_meta( Keys::REFUND_UNKNOWN );
		return is_array( $list ) ? array_values( $list ) : [];
	}

	public static function issue_token( int $order_id ): string {
		$token = bin2hex( random_bytes( 16 ) ); // 只用小寫英數：sanitize_key() 會轉小寫，有持久快取時大小寫不同就查不到。
		set_transient( 'moksafowo_rtok_' . $token, get_current_user_id() . ':' . $order_id, self::TOKEN_TTL );
		return $token;
	}

	public static function ajax_new_token(): void {
		check_ajax_referer( self::AJAX_TOKEN, 'nonce' );
		$order_id = absint( wp_unslash( $_POST['order_id'] ?? 0 ) );
		if ( ! Caps::user_can( Caps::REFUND ) || ! $order_id ) {
			wp_send_json_error();
		}
		wp_send_json_success( [ 'token' => self::issue_token( $order_id ) ] );
	}

	public static function before_refund(): void {
		// 沒有合法的 nonce 就交給 WC 自己回錯，這裡不動任何東西。
		if ( ! check_ajax_referer( 'order-item', 'security', false ) ) {
			return;
		}
		$order_id = absint( wp_unslash( $_POST['order_id'] ?? 0 ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! Caps::user_can( Caps::REFUND ) ) {
			self::reject( __( 'You do not have permission to refund orders.', 'moksa-for-woocommerce' ) );
		}
		if ( self::unknown_list( $order ) ) {
			self::reject( __( 'An earlier refund on this order has an unconfirmed result. Check it under WooCommerce > Refunds to review before refunding again.', 'moksa-for-woocommerce' ) );
		}

		$confirm = sanitize_text_field( wp_unslash( $_POST['moksafowo_refund_confirm'] ?? '' ) );
		if ( '' === $confirm || ! hash_equals( self::last4( $order ), $confirm ) ) {
			self::reject( __( 'Enter the last 4 characters of the order number to confirm this refund.', 'moksa-for-woocommerce' ) );
		}

		$token = sanitize_key( wp_unslash( $_POST['moksafowo_refund_token'] ?? '' ) );
		if ( ! self::consume_token( $token, $order_id ) ) {
			self::reject( __( 'This refund was already sent, or the page has expired. Reload the order page and check the refunds before trying again.', 'moksa-for-woocommerce' ) );
		}

		if ( ! self::lock( $order_id ) ) {
			self::reject( __( 'Another refund on this order is being processed. Wait a moment and reload the page.', 'moksa-for-woocommerce' ) );
		}

		// 拿到鎖之後才讀訂單：清掉這張單的快取，WC 算可退金額時才看得到剛完成的退款。
		self::forget_order_cache( $order_id );

		// 收下 WC 回給瀏覽器的結果：失敗時不論哪家金流都在訂單留一則備註（有些金流自己不留）。
		ob_start(
			static function ( string $chunk ): string {
				self::$captured .= $chunk;
				return $chunk;
			}
		);
		self::$buffer_level = ob_get_level();
		self::$active_order = $order_id;
		self::$api_refund   = 'true' === sanitize_key( wp_unslash( $_POST['api_refund'] ?? '' ) );
		self::$amount       = (string) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['refund_amount'] ?? '0' ) ) );
		add_action( 'http_api_debug', [ __CLASS__, 'watch_http' ], 10, 5 );
		add_action( 'shutdown', [ __CLASS__, 'finish' ], 0 );
	}

	/**
	 * 退款期間打出去的請求若失敗，記下來：WC 會撤銷退款，但金流可能已經退了。
	 *
	 * @param mixed  $response 回應或 WP_Error。
	 * @param string $context  固定 response。
	 * @param string $klass    Requests。
	 * @param array  $args     參數。
	 * @param string $url      網址。
	 */
	public static function watch_http( $response, $context, $klass, $args, $url ): void {
		if ( 'response' !== $context || false !== strpos( (string) $url, wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return;
		}
		if ( is_wp_error( $response ) ) {
			self::$http_problem = $response->get_error_message();
		} elseif ( (int) wp_remote_retrieve_response_code( $response ) >= 500 ) {
			self::$http_problem = 'HTTP ' . (int) wp_remote_retrieve_response_code( $response );
		}
	}

	/**
	 * 退款成功（WC 已建立退款紀錄並完成金流退款）：記錄、交給發票連動。所有來源（後台按鈕、
	 * 綠界卡片按鈕、REST）都會經過這裡。
	 */
	public static function after_refund( $order_id, $refund_id ): void {
		$refund = wc_get_order( (int) $refund_id );
		$order  = wc_get_order( (int) $order_id );
		if ( ! $refund instanceof \WC_Order_Refund || ! $order instanceof \WC_Order ) {
			return;
		}
		if ( (int) $order_id === self::$active_order ) {
			self::$succeeded = true;
		}

		$refund->update_meta_data( Keys::REFUND_STATE, $refund->get_refunded_payment() ? 'gateway_done' : 'manual_done' );
		self::audit( $refund, 'refund', $refund->get_refunded_payment() ? 'gateway' : 'manual' );
		$refund->save();

		$order->update_meta_data( Keys::REFUND_FLOW, 'yes' );
		$order->save();

		InvoiceSync::start( $refund );
	}

	/** 請求結束：放鎖；退款沒成功又有連線問題時記成結果未知。 */
	public static function finish(): void {
		$order_id = self::$active_order;
		if ( ! $order_id ) {
			return;
		}
		self::$active_order = 0;

		// WC 回應可能在我們之後又開的緩衝裡：先把上面的都倒進我們這層，再倒我們這層（觸發記錄）。
		if ( self::$buffer_level > 0 ) {
			while ( ob_get_level() > self::$buffer_level ) {
				ob_end_flush();
			}
			if ( ob_get_level() === self::$buffer_level ) {
				ob_end_flush();
			}
		}
		$response = self::$captured;
		if ( ! self::$succeeded && self::$api_refund && '' === self::$http_problem ) {
			$json  = json_decode( $response, true );
			$error = is_array( $json ) && empty( $json['success'] ) ? sanitize_text_field( (string) ( $json['data']['error'] ?? '' ) ) : '';
			$order = '' !== $error ? wc_get_order( $order_id ) : false;
			if ( $order instanceof \WC_Order ) {
				/* translators: 1: amount, 2: error message */
				$order->add_order_note( sprintf( __( 'The refund of %1$s did not go through: %2$s', 'moksa-for-woocommerce' ), self::money( (float) self::$amount, $order ), $error ) );
			}
		}

		if ( ! self::$succeeded && '' !== self::$http_problem && self::$api_refund && empty( self::$flagged[ $order_id ] ) ) {
			self::forget_order_cache( $order_id );
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$list   = self::unknown_list( $order );
				$amount = self::$amount;
				$list[] = [
					'amount'  => $amount,
					'time'    => time(),
					'user'    => get_current_user_id(),
					'message' => sanitize_text_field( self::$http_problem ),
				];
				$order->update_meta_data( Keys::REFUND_UNKNOWN, $list );
				$order->update_meta_data( Keys::REFUND_OPEN, 'yes' );
				$order->add_order_note(
					sprintf(
						/* translators: %s: amount */
						__( 'The refund of %s could not be confirmed with the payment provider (connection problem). It may have been refunded already. New refunds on this order are blocked until it is checked under WooCommerce > Refunds to review.', 'moksa-for-woocommerce' ),
						self::money( (float) $amount, $order )
					)
				);
				$order->save();
				PendingPage::mark( $order_id, true );
			}
		}
		self::unlock( $order_id );
	}

	public static function enqueue( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) || ! Caps::user_can( Caps::REFUND ) ) {
			return;
		}
		// 只讀網址上的訂單編號來產 token，不改任何東西。
		$order_id = absint( filter_input( INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT ) ?: filter_input( INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		wp_enqueue_script( 'moksafowo-refund-guard', MOKSAFOWO_PLUGIN_URL . 'assets/admin/moksafowo-refund-guard.js', [ 'jquery', 'wc-admin-order-meta-boxes' ], MOKSAFOWO_VERSION, true );
		wp_localize_script(
			'moksafowo-refund-guard',
			'moksafowoRefundGuard',
			[
				'orderId' => $order_id,
				'token'   => self::issue_token( $order_id ),
				'nonce'   => wp_create_nonce( self::AJAX_TOKEN ),
				'label'   => __( 'To confirm, enter the last 4 characters of the order number', 'moksa-for-woocommerce' ),
				'unknown' => self::unknown_list( $order ) ? __( 'An earlier refund on this order has an unconfirmed result. Review it under WooCommerce > Refunds to review before refunding again.', 'moksa-for-woocommerce' ) : '',
			]
		);
	}

	/**
	 * 稽核：誰、什麼時候、從哪裡、結果。只記使用者 ID 與 IP 雜湊，不記卡號、金鑰。
	 */
	public static function audit( \WC_Order_Refund $refund, string $step, string $result ): void {
		$log   = $refund->get_meta( Keys::REFUND_AUDIT );
		$log   = is_array( $log ) ? $log : [];
		$ip    = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$log[] = [
			'step'    => $step,
			'result'  => $result,
			'user'    => get_current_user_id(),
			'time'    => time(),
			'source'  => wp_doing_ajax() ? 'admin' : ( wp_doing_cron() ? 'cron' : ( defined( 'REST_REQUEST' ) && REST_REQUEST ? 'rest' : 'other' ) ),
			'ip_hash' => '' === $ip ? '' : substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 16 ),
		];
		$refund->update_meta_data( Keys::REFUND_AUDIT, $log );
	}

	/**
	 * 原子鎖：add_option 底層是 INSERT，同名只會有一個成功。值是「時間|本次識別碼」，
	 * 放鎖時比對是自己的才刪（不會解掉別人的鎖）。過期才清掉重搶：LOCK_TTL 要比最長的
	 * 退款流程長（統一金流最多 5 次請求、每次逾時 30 秒）。
	 */
	public static function lock( int $order_id, string $kind = 'refund' ): bool {
		$name  = 'moksafowo_lock_' . $kind . '_' . $order_id;
		$value = time() . '|' . bin2hex( random_bytes( 8 ) );
		if ( add_option( $name, $value, '', false ) ) {
			self::$locks[ $name ] = $value;
			return true;
		}
		$held  = self::fresh_option( $name );
		$since = (int) strtok( (string) $held, '|' );
		if ( $since > 0 && time() - $since > self::LOCK_TTL && self::fresh_option( $name ) === $held ) {
			delete_option( $name );
			if ( add_option( $name, $value, '', false ) ) {
				self::$locks[ $name ] = $value;
				return true;
			}
		}
		return false;
	}

	public static function unlock( int $order_id, string $kind = 'refund' ): void {
		$name = 'moksafowo_lock_' . $kind . '_' . $order_id;
		if ( isset( self::$locks[ $name ] ) && self::fresh_option( $name ) === self::$locks[ $name ] ) {
			delete_option( $name );
		}
		unset( self::$locks[ $name ] );
	}

	/** 這個請求是不是已經拿著這張單的退款鎖（後台按鈕先拿了，WC 建退款時不要再拿一次）。 */
	public static function holds( int $order_id, string $kind = 'refund' ): bool {
		return isset( self::$locks[ 'moksafowo_lock_' . $kind . '_' . $order_id ] );
	}

	/** 繞過快取直接讀（有 Redis 時 notoptions 可能是舊的，見 SOP §3 G）。 */
	private static function fresh_option( string $name ) {
		wp_cache_delete( $name, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		return get_option( $name, false );
	}

	/**
	 * 所有退款來源（WC REST、綠界卡片按鈕、其他外掛）在 WC 建退款紀錄前都會經過：
	 * 有結果未知的退款就擋；沒拿鎖的來源在這裡拿，請求結束放。丟例外 WC 會回失敗、不建紀錄。
	 *
	 * @param \WC_Order_Refund $refund 還沒存的退款紀錄。
	 * @param array            $args   wc_create_refund 參數。
	 * @throws \Exception 擋下時，WC 會把它轉成退款失敗.
	 */
	public static function before_create_refund( $refund, $args ): void {
		$order_id = absint( $args['order_id'] ?? 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$problem = self::not_ready( $order );
		if ( null !== $problem ) {
			throw new \Exception( esc_html( $problem ) );
		}
	}

	/**
	 * 退款前的共通檢查（後台按鈕以外的來源也用，例如綠界卡片的放棄授權會先打金流再記退款）。
	 * 回傳擋下的原因；可以退就拿好鎖（請求結束自動放）並回 null。
	 */
	public static function not_ready( \WC_Order $order ): ?string {
		if ( self::unknown_list( $order ) ) {
			return __( 'An earlier refund on this order has an unconfirmed result. Check it under WooCommerce > Refunds to review before refunding again.', 'moksa-for-woocommerce' );
		}
		if ( ! self::holds( $order->get_id() ) ) {
			if ( ! self::lock( $order->get_id() ) ) {
				return __( 'Another refund on this order is being processed. Wait a moment and reload the page.', 'moksa-for-woocommerce' );
			}
			$id = $order->get_id();
			add_action( 'shutdown', static fn() => self::unlock( $id ), 1 );
		}
		return null;
	}

	/**
	 * 金流多步流程停在不確定的狀態（例如已取消請款、重送失敗，這筆現在沒有請款）：
	 * 跟結果未知一樣列進待處理、擋同單後續退款，並寄信給管理員。
	 */
	public static function flag( \WC_Order $order, string $amount, string $message ): void {
		self::$flagged[ $order->get_id() ] = true;
		$list                              = self::unknown_list( $order );
		$list[]                            = [
			'amount'  => $amount,
			'time'    => time(),
			'user'    => get_current_user_id(),
			'message' => sanitize_text_field( $message ),
		];
		$order->update_meta_data( Keys::REFUND_UNKNOWN, $list );
		$order->update_meta_data( Keys::REFUND_OPEN, 'yes' );
		$order->save();
		PendingPage::mark( $order->get_id(), true );
		wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: order number */
			sprintf( __( 'A refund needs your attention (order #%s)', 'moksa-for-woocommerce' ), $order->get_order_number() ),
			$message . "\n\n" . admin_url( 'admin.php?page=' . PendingPage::SLUG )
		);
	}

	private static function consume_token( string $token, int $order_id ): bool {
		if ( '' === $token ) {
			return false;
		}
		$owner = get_transient( 'moksafowo_rtok_' . $token );
		if ( get_current_user_id() . ':' . $order_id !== $owner ) {
			return false;
		}
		// 刪除也要原子：同一個 token 同時送兩次，只有搶到「已用」標記的那個算數。
		if ( ! add_option( 'moksafowo_rtok_used_' . $token, (string) time(), '', false ) ) {
			return false;
		}
		delete_transient( 'moksafowo_rtok_' . $token );
		wp_schedule_single_event( time() + self::TOKEN_TTL, 'moksafowo_refund_token_cleanup', [ $token ] );
		return true;
	}

	public static function cleanup_token( string $token ): void {
		delete_option( 'moksafowo_rtok_used_' . sanitize_key( $token ) );
	}

	private static function forget_order_cache( int $order_id ): void {
		clean_post_cache( $order_id );
		if ( class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) && function_exists( 'wc_get_container' ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}

	/** 備註用的純文字金額（wc_price 會輸出 HTML 實體，拿掉標籤後還是 &#78;&#84;&#36;）。 */
	private static function money( float $amount, \WC_Order $order ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, [ 'currency' => $order->get_currency() ] ) ), ENT_QUOTES, 'UTF-8' );
	}

	private static function reject( string $message ): void {
		wp_send_json_error( [ 'error' => $message ] );
	}
}
