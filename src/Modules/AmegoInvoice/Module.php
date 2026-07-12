<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\AmegoInvoice;

use Moksafowo\Modules\AbstractModule;
use Moksafowo\Modules\AmegoInvoice\Operations\Issue;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public const ASYNC_ISSUE_HOOK = 'moksafowo_amego_invoice_async_issue';

	public function slug(): string {
		return 'amego_invoice';
	}

	public function label(): string {
		return __( 'AMEGO 光貿電子發票 — B2C / B2B / 手機條碼 / 自然人憑證 / 會員載具 / 捐贈', 'mo-ectools' );
	}

	public function category(): string {
		return 'invoice';
	}

	public function name(): string {
		return __( 'AMEGO 電子發票', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( 'B2C / B2B / 載具 / 捐贈 — 光貿', 'mo-ectools' );
	}

	public function methods(): array {
		return [
			__( 'B2C 一般發票', 'mo-ectools' ),
			__( 'B2B 三聯式', 'mo-ectools' ),
			__( '手機條碼', 'mo-ectools' ),
			__( '自然人憑證', 'mo-ectools' ),
			__( 'AMEGO 會員載具', 'mo-ectools' ),
			__( '愛心碼捐贈', 'mo-ectools' ),
			__( '作廢發票', 'mo-ectools' ),
		];
	}

	public function settings_section(): string {
		return 'amego-invoice';
	}

	public function boot(): void {
		Frontend\CheckoutFields::init();
		if ( is_admin() ) {
			Admin\OrderMetaBox::init();
		}

		$when = (string) get_option( 'moksafowo_amego_invoice_issue_when', 'paid' );
		if ( 'paid' === $when ) {
			add_action( 'woocommerce_payment_complete', [ __CLASS__, 'maybe_issue' ], 30 );
			add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'maybe_issue' ], 30 );
		} elseif ( 'completed' === $when ) {
			add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'maybe_issue' ], 30 );
		}

		add_action( self::ASYNC_ISSUE_HOOK, [ __CLASS__, 'async_issue' ], 10, 1 );

		if ( 'auto_cancel' === get_option( 'moksafowo_amego_invoice_auto_cancel', 'manual' ) ) {
			add_action( 'woocommerce_order_status_cancelled', [ Operations\AutoInvalid::class, 'schedule' ] );
			add_action( 'woocommerce_order_status_refunded', [ Operations\AutoInvalid::class, 'schedule' ] );
			add_action( 'woocommerce_order_status_failed', [ Operations\AutoInvalid::class, 'schedule' ] );
		}
		add_action( Operations\AutoInvalid::HOOK, [ Operations\AutoInvalid::class, 'run' ], 10, 1 );
	}

	public static function maybe_issue( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( $order->get_meta( Keys::AMEGO_INVOICE_NUMBER ) ) {
			return;
		}
		// 優先序：ECPay > ezPay > SmilePay > PayNow > Amego（最後 fallback）
		$provider          = (string) $order->get_meta( Keys::INVOICE_PROVIDER );
		$other_provider_on = 'yes' === get_option( 'moksafowo_ezpay_invoice_enabled', 'no' )
			|| 'yes' === get_option( 'moksafowo_ecpay_invoice_enabled', 'no' )
			|| 'yes' === get_option( 'moksafowo_smilepay_invoice_enabled', 'no' )
			|| 'yes' === get_option( 'moksafowo_paynow_invoice_enabled', 'no' );

		if ( '' !== $provider && 'amego' !== $provider ) {
			return;
		}
		if ( '' === $provider && $other_provider_on ) {
			return;
		}
		// Dedupe：payment_complete + status_processing 都會觸發本 hook。
		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( self::ASYNC_ISSUE_HOOK, [ $order_id ], 'mo-ectools' ) ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::ASYNC_ISSUE_HOOK, [ $order_id ], 'mo-ectools' );
		} else {
			wp_schedule_single_event( time(), self::ASYNC_ISSUE_HOOK, [ $order_id ] );
		}
	}

	public static function async_issue( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( $order->get_meta( Keys::AMEGO_INVOICE_NUMBER ) ) {
			return;
		}
		Issue::run( $order );
	}
}
