<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayInvoice;

use MoksaWeb\Mowc\Modules\AbstractModule;
use MoksaWeb\Mowc\Modules\EcpayInvoice\Operations\Issue;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'ecpay_invoice';
	}

	public function label(): string {
		return __( '綠界電子發票 — B2C / B2B / 手機條碼 / 自然人憑證 / 會員載具 / 捐贈 / 折讓 / 作廢', 'mo-ectools' );
	}

	public function category(): string {
		return 'invoice';
	}

	public function name(): string {
		return __( '綠界電子發票', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( 'B2C / B2B / 載具 / 捐贈 — 自動開立、折讓、作廢', 'mo-ectools' );
	}

	public function methods(): array {
		return [
			__( 'B2C 一般發票', 'mo-ectools' ),
			__( 'B2B 三聯式', 'mo-ectools' ),
			__( '手機條碼', 'mo-ectools' ),
			__( '自然人憑證', 'mo-ectools' ),
			__( '會員載具', 'mo-ectools' ),
			__( '愛心碼捐贈', 'mo-ectools' ),
			__( '折讓單', 'mo-ectools' ),
			__( '作廢發票', 'mo-ectools' ),
		];
	}

	public function settings_section(): string {
		return 'ecpay-invoice';
	}

	public function boot(): void {
		// 結帳頁載具欄位（Classic + Block）
		Frontend\CheckoutFields::init();

		// 訂單編輯頁 meta box
		if ( is_admin() ) {
			Admin\OrderMetaBox::init();
		}

		// 自動開立
		$when = (string) get_option( 'mo_ecpay_invoice_issue_when', 'paid' );
		if ( 'paid' === $when ) {
			add_action( 'woocommerce_payment_complete', [ __CLASS__, 'maybe_issue' ], 30 );
			add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'maybe_issue' ], 30 );
		} elseif ( 'completed' === $when ) {
			add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'maybe_issue' ], 30 );
		}

		// 延後開立 — 透過 Action Scheduler / WP-Cron 在 N 天後跑 deferred_issue
		add_action( 'mo_ecpay_invoice_deferred_issue', [ __CLASS__, 'deferred_issue' ], 10, 1 );

		// 訂單退款 / 取消時自動作廢發票。預設 manual — 商家進設定主動開啟（保守安全）。
		if ( 'auto_cancel' === get_option( 'mo_ecpay_invoice_auto_cancel', 'manual' ) ) {
			add_action( 'woocommerce_order_status_cancelled', [ Operations\AutoInvalid::class, 'schedule' ] );
			add_action( 'woocommerce_order_status_refunded',  [ Operations\AutoInvalid::class, 'schedule' ] );
			add_action( 'woocommerce_order_status_failed',    [ Operations\AutoInvalid::class, 'schedule' ] );
		}
		// 不論 toggle 開關，都掛 Action Scheduler callback — 既有排程要能跑完
		add_action( Operations\AutoInvalid::HOOK, [ Operations\AutoInvalid::class, 'run' ], 10, 1 );
	}

	public static function maybe_issue( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( $order->get_meta( Keys::ECPAY_INVOICE_NUMBER ) ) {
			return;
		}
		// 單一路由（無雙開）：ECPay 為預設 provider，但若此單已由別家發票模組接管
		// （結帳時 INVOICE_PROVIDER 設成 ezpay/smilepay/paynow/amego），ECPay 不得也開立，
		// 否則兩家同時開 → 重複發票。其餘 4 模組已有對應 gate，這裡補上 ECPay 的。
		$provider = (string) $order->get_meta( Keys::INVOICE_PROVIDER );
		if ( '' !== $provider && 'ecpay' !== $provider ) {
			return;
		}
		// Dedupe：payment_complete + status_processing 都會觸發本 hook，AS 沒有 args-based 預設 dedupe。
		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( 'mo_ecpay_invoice_deferred_issue', [ $order_id ], 'mo-ectools' ) ) {
			return;
		}

		$delay_days = max( 0, min( 30, (int) get_option( 'mo_ecpay_invoice_delay_days', 0 ) ) );

		if ( $delay_days > 0 && $order->get_meta( Keys::ECPAY_INVOICE_SCHEDULED_AT ) ) {
			return;
		}

		$run_at = time() + ( $delay_days * DAY_IN_SECONDS );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $run_at, 'mo_ecpay_invoice_deferred_issue', [ $order_id ], 'mo-ectools' );
		} else {
			wp_schedule_single_event( $run_at, 'mo_ecpay_invoice_deferred_issue', [ $order_id ] );
		}

		if ( $delay_days > 0 ) {
			$order->update_meta_data( Keys::ECPAY_INVOICE_SCHEDULED_AT, current_time( 'mysql' ) );
			$order->add_order_note( sprintf(
				/* translators: %d: delay days */
				__( '綠界發票排程於 %d 天後開立。', 'mo-ectools' ),
				$delay_days
			) );
			$order->save();
		}
	}

	public static function deferred_issue( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( $order->get_meta( Keys::ECPAY_INVOICE_NUMBER ) ) {
			return; // 已開過
		}
		if ( in_array( $order->get_status(), [ 'cancelled', 'refunded', 'failed' ], true ) ) {
			$order->add_order_note( __( '綠界發票排程取消（訂單已取消 / 退款 / 失敗）。', 'mo-ectools' ) );
			$order->save();
			return;
		}
		Issue::run( $order );
	}
}
