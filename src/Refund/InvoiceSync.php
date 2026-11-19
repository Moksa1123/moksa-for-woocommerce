<?php

declare( strict_types=1 );

namespace Moksafowo\Refund;

use Moksafowo\Modules\OrderLookup\InvoiceOps;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 退款成功後的發票處理（設計 §七）：
 * - 全額退、發票在本期（同一個雙月申報期）、沒折讓過 → 作廢
 * - 部分退或跨期 → 折讓（金額＝本次退款；不能超過發票金額−已折讓）
 * - 發票還沒開：全額退取消排程；部分退列入待處理，請店長開立時留意金額
 * - 發票商沒有折讓 API（速買配、PayNow）→ 列入待處理，請店長到後台手動
 *
 * 發票設定成「自動作廢」才自動執行（排 Action Scheduler，失敗重試 3 次）；手動時列在
 * 「退款待處理」由店長按執行。錢的退款不因發票失敗而回滾；任何沒有明確成功的都留在清單。
 */
final class InvoiceSync {

	public const HOOK = 'moksafowo_refund_invoice';

	private const RETRY = [ 300, 1800, 7200 ];

	private const AUTO_INVALID = [
		'ecpay'    => \Moksafowo\Modules\EcpayInvoice\Operations\AutoInvalid::class,
		'ezpay'    => \Moksafowo\Modules\EzpayInvoice\Operations\AutoInvalid::class,
		'smilepay' => \Moksafowo\Modules\SmilepayInvoice\Operations\AutoInvalid::class,
		'paynow'   => \Moksafowo\Modules\PaynowInvoice\Operations\AutoInvalid::class,
		'amego'    => \Moksafowo\Modules\AmegoInvoice\Operations\AutoInvalid::class,
	];

	private const ISSUED_AT = [
		'ecpay'    => Keys::ECPAY_INVOICE_ISSUED_AT,
		'ezpay'    => Keys::EZPAY_CREATE_TIME,
		'smilepay' => Keys::SMILEPAY_INVOICE_DATE,
		'paynow'   => Keys::PAYNOW_INVOICE_ISSUED_AT,
		'amego'    => Keys::AMEGO_INVOICE_ISSUED_AT,
	];

	private const INVALID_AT = [
		'ecpay'    => Keys::ECPAY_INVOICE_INVALID_AT,
		'ezpay'    => Keys::EZPAY_INVALID_AT,
		'smilepay' => Keys::SMILEPAY_INVOICE_INVALID_AT,
		'paynow'   => Keys::PAYNOW_INVOICE_INVALID_AT,
		'amego'    => Keys::AMEGO_INVOICE_INVALID_AT,
	];

	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ], 10, 1 );
	}

	/** 退款完成時呼叫：決定要怎麼處理發票，記在退款紀錄上，自動模式就排程。 */
	public static function start( \WC_Order_Refund $refund ): void {
		$order = wc_get_order( $refund->get_parent_id() );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		[ $state, $ref ] = self::plan( $order, $refund );
		$refund->update_meta_data( Keys::REFUND_INVOICE_STATE, $state );
		$refund->update_meta_data( Keys::REFUND_INVOICE_REF, $ref );
		$refund->save();

		if ( in_array( $state, [ 'todo_void', 'todo_allowance', 'todo_cancel_schedule' ], true ) && self::auto( $order ) ) {
			self::schedule( $refund->get_id(), 30 );
		}
		self::refresh_open( $order );
	}

	/**
	 * @return array{0:string,1:string} [ 狀態, 說明或金額 ]
	 */
	public static function plan( \WC_Order $order, \WC_Order_Refund $refund ): array {
		$p = InvoiceOps::provider_for( $order );
		if ( ! $p ) {
			return [ 'skipped', __( 'No e-invoice module is enabled.', 'moksa-for-woocommerce' ) ];
		}
		$slug   = $p['slug'];
		$number = (string) $order->get_meta( $p['number_key'] );
		$full   = (float) $order->get_remaining_refund_amount() <= 0.0;
		$amount = (int) round( (float) $refund->get_amount() );

		if ( '' !== (string) $order->get_meta( self::INVALID_AT[ $slug ] ) ) {
			return [ 'skipped', __( 'The invoice was already voided.', 'moksa-for-woocommerce' ) ];
		}
		// 台灣發票字軌：2 個英文字母＋8 位數字；不是這個格式就當作還沒開（排程中、失敗殘值）。
		if ( ! preg_match( '/^[A-Z]{2}[0-9]{8}$/', $number ) ) {
			return $full
				? [ 'todo_cancel_schedule', '' ]
				: [ 'todo_manual', __( 'The invoice has not been issued yet. When it is issued, make sure the amount reflects this refund.', 'moksa-for-woocommerce' ) ];
		}

		// 已開的折讓＋其他退款還在等的折讓都要算：前一筆部分退款的折讓還沒開（排程中或等店長按），
		// 這筆全額退時不能判成作廢，否則同一張發票同時有待開折讓和待作廢。
		$prior = self::allowed_so_far( $order, $slug );
		foreach ( $order->get_refunds() as $other ) {
			if ( $other->get_id() !== $refund->get_id() && 'todo_allowance' === (string) $other->get_meta( Keys::REFUND_INVOICE_STATE ) ) {
				$prior += (int) $other->get_meta( Keys::REFUND_INVOICE_REF );
			}
		}
		if ( $full && 0 === $prior && self::same_period( (string) $order->get_meta( self::ISSUED_AT[ $slug ] ) ) ) {
			return [ 'todo_void', '' ];
		}
		if ( null === $p['allowance'] ) {
			return [ 'todo_manual', __( 'This e-invoice service has no allowance API here. Issue the allowance in its dashboard.', 'moksa-for-woocommerce' ) ];
		}
		$cap = (int) round( (float) $order->get_total() ) - $prior;
		if ( $amount > $cap ) {
			/* translators: 1: refund amount, 2: amount still available for allowances */
			return [ 'failed', sprintf( __( 'The allowance of NT$%1$d is more than the NT$%2$d left on the invoice.', 'moksa-for-woocommerce' ), $amount, max( 0, $cap ) ) ];
		}
		return [ 'todo_allowance', (string) $amount ];
	}

	/** 執行一筆退款的發票動作（排程或店長在清單按下）。 */
	public static function run( $refund_id ): void {
		$refund = wc_get_order( (int) $refund_id );
		if ( ! $refund instanceof \WC_Order_Refund ) {
			return;
		}
		$order_id = (int) $refund->get_parent_id();
		if ( ! Guard::lock( $order_id, 'invoice' ) ) {
			self::schedule( (int) $refund_id, 60 );
			return;
		}
		try {
			$refund = wc_get_order( (int) $refund_id );
			$order  = wc_get_order( $order_id );
			$state  = (string) $refund->get_meta( Keys::REFUND_INVOICE_STATE );
			if ( ! $order instanceof \WC_Order || ! in_array( $state, [ 'todo_void', 'todo_allowance', 'todo_cancel_schedule' ], true ) ) {
				return; // 已處理過（重送、重複排程）就不再做。
			}
			$p = InvoiceOps::provider_for( $order );
			if ( ! $p ) {
				$result = [
					'ok'      => false,
					'message' => __( 'No e-invoice module is enabled.', 'moksa-for-woocommerce' ),
				];
			} elseif ( 'todo_void' === $state ) {
				/* translators: %s: refund ID */
				$result = (array) call_user_func( [ $p['invalid'], 'run' ], $order, sprintf( __( 'Refund #%s', 'moksa-for-woocommerce' ), $refund->get_id() ) );
			} elseif ( 'todo_allowance' === $state ) {
				$amount = (int) $refund->get_meta( Keys::REFUND_INVOICE_REF );
				$result = (array) call_user_func( [ $p['allowance'], 'run' ], $order, $amount );
				if ( ! empty( $result['ok'] ) ) {
					$order = wc_get_order( $order_id );
					$order->update_meta_data( Keys::REFUND_ALLOWANCE_SUM, (int) $order->get_meta( Keys::REFUND_ALLOWANCE_SUM ) + $amount );
					$order->save();
				}
			} else {
				$class = self::AUTO_INVALID[ $p['slug'] ];
				call_user_func( [ $class, 'run' ], $order_id, true );
				// 不能只假設成功：訂單狀態若被改回別的，AutoInvalid 只留備註就返回，排程的發票照樣會開。
				// 排程旗標清掉了才算數。
				$key    = ( new \ReflectionMethod( $class, 'scheduled_meta_key' ) )->invoke( null );
				$after  = wc_get_order( $order_id );
				$result = '' === $key || '' === (string) $after->get_meta( $key )
					? [ 'ok' => true ]
					: [
						'ok'      => false,
						'message' => __( 'The scheduled invoice could not be cancelled. Check it in the e-invoice dashboard.', 'moksa-for-woocommerce' ),
					];
			}

			$refund = wc_get_order( (int) $refund_id );
			if ( ! empty( $result['ok'] ) ) {
				$refund->update_meta_data( Keys::REFUND_INVOICE_STATE, 'done' );
				$refund->delete_meta_data( Keys::REFUND_INVOICE_ERROR );
				Guard::audit( $refund, 'invoice', $state . ':ok' );
			} else {
				$tries = (int) $refund->get_meta( Keys::REFUND_INVOICE_TRIES ) + 1;
				$error = sanitize_text_field( (string) ( $result['message'] ?? '' ) );
				$refund->update_meta_data( Keys::REFUND_INVOICE_TRIES, $tries );
				$refund->update_meta_data( Keys::REFUND_INVOICE_ERROR, $error );
				Guard::audit( $refund, 'invoice', $state . ':fail' );
				// 折讓不自動重試：逾時時加值中心可能已經開了，再送會多開一張；作廢重送最多回「已作廢」，安全。
				if ( 'todo_allowance' !== $state && $tries <= count( self::RETRY ) && self::auto( $order ) && ! wp_doing_ajax() ) {
					self::schedule( (int) $refund_id, self::RETRY[ $tries - 1 ] );
				} else {
					$refund->update_meta_data( Keys::REFUND_INVOICE_STATE, 'failed' );
					self::notify_failure( $order, $refund, $error );
				}
			}
			$refund->save();
			self::refresh_open( wc_get_order( $order_id ) );
		} finally {
			Guard::unlock( $order_id, 'invoice' );
		}
	}

	/** 店長在清單略過（要填原因）。 */
	public static function skip( \WC_Order_Refund $refund, string $reason ): void {
		$refund->update_meta_data( Keys::REFUND_INVOICE_STATE, 'skipped' );
		$refund->update_meta_data( Keys::REFUND_INVOICE_REF, $reason );
		Guard::audit( $refund, 'invoice', 'skipped' );
		$refund->save();
		self::refresh_open( wc_get_order( $refund->get_parent_id() ) );
	}

	/** 訂單的待處理旗標：有結果未知的退款，或有發票待處理／失敗。 */
	public static function refresh_open( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$open = (bool) Guard::unknown_list( $order );
		foreach ( $order->get_refunds() as $refund ) {
			if ( in_array( (string) $refund->get_meta( Keys::REFUND_INVOICE_STATE ), [ 'todo_void', 'todo_allowance', 'todo_cancel_schedule', 'todo_manual', 'failed' ], true ) ) {
				$open = true;
				break;
			}
		}
		if ( $open ) {
			$order->update_meta_data( Keys::REFUND_OPEN, 'yes' );
		} else {
			$order->delete_meta_data( Keys::REFUND_OPEN );
		}
		$order->save();
		PendingPage::mark( $order->get_id(), $open );
	}

	/** 開立日與今天是不是同一個雙月申報期（1–2、3–4…月），以網站時區算。 */
	public static function same_period( string $issued_at, ?int $now = null ): bool {
		if ( '' === $issued_at ) {
			return false;
		}
		// 申報期是台灣的雙月期：固定台北時區，不跟網站時區（網站設別的時區時交界會判錯）。
		$tz = new \DateTimeZone( 'Asia/Taipei' );
		try {
			$issued = ctype_digit( $issued_at ) ? ( new \DateTimeImmutable( '@' . $issued_at ) )->setTimezone( $tz ) : new \DateTimeImmutable( $issued_at, $tz );
		} catch ( \Exception $e ) {
			return false;
		}
		$today = ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $tz );
		$key   = static fn( \DateTimeImmutable $d ): int => (int) $d->format( 'Y' ) * 6 + intdiv( (int) $d->format( 'n' ) - 1, 2 );
		return $key( $issued ) === $key( $today );
	}

	private static function allowed_so_far( \WC_Order $order, string $slug ): int {
		$sum  = (int) $order->get_meta( Keys::REFUND_ALLOWANCE_SUM );
		$keys = [
			'ecpay' => Keys::ECPAY_INVOICE_ALLOWANCES,
			'amego' => Keys::AMEGO_INVOICE_ALLOWANCES,
		];
		if ( isset( $keys[ $slug ] ) ) {
			$list  = $order->get_meta( $keys[ $slug ] );
			$total = 0;
			foreach ( is_array( $list ) ? $list : [] as $row ) {
				if ( empty( $row['voided'] ) ) {
					$total += (int) ( $row['amount'] ?? 0 );
				}
			}
			$sum = max( $sum, $total );
		}
		return $sum;
	}

	private static function auto( \WC_Order $order ): bool {
		$p = InvoiceOps::provider_for( $order );
		return $p && 'auto_cancel' === get_option( 'moksafowo_' . $p['slug'] . '_invoice_auto_cancel', 'manual' );
	}

	private static function schedule( int $refund_id, int $delay ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK, [ $refund_id ], 'moksa-for-woocommerce' );
		}
	}

	private static function notify_failure( \WC_Order $order, \WC_Order_Refund $refund, string $error ): void {
		$order->add_order_note(
			/* translators: 1: refund ID, 2: error message */
			sprintf( __( 'The e-invoice step for refund #%1$s failed: %2$s. It is listed under WooCommerce > Refunds to review.', 'moksa-for-woocommerce' ), $refund->get_id(), $error )
		);
		if ( 'yes' !== get_option( 'moksafowo_refund_invoice_fail_email', 'yes' ) ) {
			return;
		}
		wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: order number */
			sprintf( __( 'Refund invoice needs attention (order #%s)', 'moksa-for-woocommerce' ), $order->get_order_number() ),
			sprintf(
				/* translators: 1: order number, 2: error message, 3: admin URL */
				__( "The e-invoice step for a refund on order #%1\$s failed: %2\$s\n\nThe refund itself went through. Review it here: %3\$s", 'moksa-for-woocommerce' ),
				$order->get_order_number(),
				$error,
				admin_url( 'admin.php?page=' . PendingPage::SLUG )
			)
		);
	}
}
