<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EzpayInvoice;

use Moksafowo\Modules\AbstractModule;
use Moksafowo\Modules\EzpayInvoice\Operations\Issue;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'ezpay_invoice';
	}

	public function label(): string {
		return __( 'ezPay 電子發票 — B2C / B2B / 手機條碼 / 自然人憑證 / 會員載具 / 捐贈', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'invoice';
	}

	public function name(): string {
		return __( 'ezPay 電子發票', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( 'B2C / B2B / 載具 / 捐贈 — 藍新旗下', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( 'B2C 一般發票', 'moksa-for-woocommerce' ),
			__( 'B2B 三聯式', 'moksa-for-woocommerce' ),
			__( '手機條碼', 'moksa-for-woocommerce' ),
			__( '自然人憑證', 'moksa-for-woocommerce' ),
			__( 'ezPay 會員載具', 'moksa-for-woocommerce' ),
			__( '愛心碼捐贈', 'moksa-for-woocommerce' ),
			__( '作廢發票', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'ezpay-invoice';
	}

	public const ASYNC_ISSUE_HOOK = 'moksafowo_ezpay_invoice_async_issue';

	public function boot(): void {
		Frontend\CheckoutFields::init();
		if ( is_admin() ) {
			Admin\OrderMetaBox::init();
		}

		$when = (string) get_option( 'moksafowo_ezpay_invoice_issue_when', 'paid' );
		if ( 'paid' === $when ) {
			add_action( 'woocommerce_payment_complete', [ __CLASS__, 'maybe_issue' ], 30 );
			add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'maybe_issue' ], 30 );
		} elseif ( 'completed' === $when ) {
			add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'maybe_issue' ], 30 );
		}

		add_action( self::ASYNC_ISSUE_HOOK, [ __CLASS__, 'async_issue' ], 10, 1 );

		if ( 'auto_cancel' === get_option( 'moksafowo_ezpay_invoice_auto_cancel', 'manual' ) ) {
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
		if ( $order->get_meta( Keys::EZPAY_INVOICE_NUMBER ) ) {
			return;
		}
		$provider      = (string) $order->get_meta( Keys::INVOICE_PROVIDER );
		$ecpay_enabled = 'yes' === get_option( 'moksafowo_ecpay_invoice_enabled', 'no' );
		if ( '' !== $provider && 'ezpay' !== $provider ) {
			return;
		}
		if ( '' === $provider && $ecpay_enabled ) {
			return;
		}
		// Dedupe：payment_complete + status_processing 都會觸發本 hook。
		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( self::ASYNC_ISSUE_HOOK, [ $order_id ], 'moksa-for-woocommerce' ) ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::ASYNC_ISSUE_HOOK, [ $order_id ], 'moksa-for-woocommerce' );
		} else {
			wp_schedule_single_event( time(), self::ASYNC_ISSUE_HOOK, [ $order_id ] );
		}
	}

	public static function async_issue( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( $order->get_meta( Keys::EZPAY_INVOICE_NUMBER ) ) {
			return;
		}
		Issue::run( $order );
	}
}
