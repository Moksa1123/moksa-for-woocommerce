<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayInvoice;

use Moksafowo\Modules\AbstractModule;
use Moksafowo\Modules\EcpayInvoice\Operations\Issue;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'ecpay_invoice';
	}

	public function label(): string {
		return __( 'ECPay e-invoice — B2C and B2B, mobile barcode, Citizen Digital Certificate, member carrier, donation, allowance and voiding', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'invoice';
	}

	public function name(): string {
		return __( 'ECPay e-invoice', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( 'B2C, B2B, carriers and donations, issued, credited and voided automatically', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( 'B2C standard invoice', 'moksa-for-woocommerce' ),
			__( 'B2B triplicate invoice', 'moksa-for-woocommerce' ),
			__( 'Mobile barcode', 'moksa-for-woocommerce' ),
			__( 'Citizen Digital Certificate', 'moksa-for-woocommerce' ),
			__( 'Member carrier', 'moksa-for-woocommerce' ),
			__( 'Donation by love code', 'moksa-for-woocommerce' ),
			__( 'Allowance', 'moksa-for-woocommerce' ),
			__( 'Void invoice', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'ecpay-invoice';
	}

	public function boot(): void {
		Frontend\CheckoutFields::init();

		if ( is_admin() ) {
			Admin\OrderMetaBox::init();
		}

		$when = (string) get_option( 'moksafowo_ecpay_invoice_issue_when', 'paid' );
		if ( 'paid' === $when ) {
			add_action( 'woocommerce_payment_complete', [ __CLASS__, 'maybe_issue' ], 30 );
			add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'maybe_issue' ], 30 );
		} elseif ( 'completed' === $when ) {
			add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'maybe_issue' ], 30 );
		}

		add_action( 'moksafowo_ecpay_invoice_deferred_issue', [ __CLASS__, 'deferred_issue' ], 10, 1 );

		if ( 'auto_cancel' === get_option( 'moksafowo_ecpay_invoice_auto_cancel', 'manual' ) ) {
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
		if ( $order->get_meta( Keys::ECPAY_INVOICE_NUMBER ) ) {
			return;
		}
		// Prevent double-issue: if another provider was selected at checkout, skip.
		$provider = (string) $order->get_meta( Keys::INVOICE_PROVIDER );
		if ( '' !== $provider && 'ecpay' !== $provider ) {
			return;
		}
		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( 'moksafowo_ecpay_invoice_deferred_issue', [ $order_id ], 'moksa-for-woocommerce' ) ) {
			return;
		}

		$delay_days = max( 0, min( 30, (int) get_option( 'moksafowo_ecpay_invoice_delay_days', 0 ) ) );

		if ( $delay_days > 0 && $order->get_meta( Keys::ECPAY_INVOICE_SCHEDULED_AT ) ) {
			return;
		}

		$run_at = time() + ( $delay_days * DAY_IN_SECONDS );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $run_at, 'moksafowo_ecpay_invoice_deferred_issue', [ $order_id ], 'moksa-for-woocommerce' );
		} else {
			wp_schedule_single_event( $run_at, 'moksafowo_ecpay_invoice_deferred_issue', [ $order_id ] );
		}

		if ( $delay_days > 0 ) {
			$order->update_meta_data( Keys::ECPAY_INVOICE_SCHEDULED_AT, current_time( 'mysql' ) );
			$order->add_order_note(
				sprintf(
				/* translators: %d: delay days */
					__( 'The ECPay invoice is scheduled to be issued in %d days.', 'moksa-for-woocommerce' ),
					$delay_days
				)
			);
			$order->save();
		}
	}

	public static function deferred_issue( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( $order->get_meta( Keys::ECPAY_INVOICE_NUMBER ) ) {
			return;
		}
		if ( in_array( $order->get_status(), [ 'cancelled', 'refunded', 'failed' ], true ) ) {
			$order->add_order_note( __( 'The scheduled ECPay invoice was cancelled because the order was cancelled, refunded or failed.', 'moksa-for-woocommerce' ) );
			$order->save();
			return;
		}
		Issue::run( $order );
	}
}
