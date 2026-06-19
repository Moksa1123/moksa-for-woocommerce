<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shared\Invoice;

defined( 'ABSPATH' ) || exit;

abstract class AbstractAutoInvalid {

	public const BUFFER_SECONDS = 120;

	abstract protected static function hook_name(): string;

	abstract protected static function provider_label(): string;

	abstract protected static function invoice_number_meta_key(): string;

	abstract protected static function scheduled_meta_key(): string;

	abstract protected static function deferred_issue_hook_name(): string;

	abstract protected static function invoke_invalid( \WC_Order $order, string $reason ): void;

	protected static function is_real_invoice_number( string $invoice_no ): bool {
		return '' !== $invoice_no;
	}

	public static function schedule( int $order_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			static::run( $order_id );
			return;
		}
		$hook = static::hook_name();
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, [ $order_id ], 'mo-ectools' ) ) {
			return;
		}
		as_schedule_single_action( time() + static::BUFFER_SECONDS, $hook, [ $order_id ], 'mo-ectools' );
	}

	public static function run( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$status = $order->get_status();
		if ( ! in_array( $status, [ 'cancelled', 'refunded', 'failed' ], true ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: provider label */
					__( '%1$s 發票自動作廢已取消（訂單已切回非取消 / 退款狀態）。', 'mo-ectools' ),
					static::provider_label()
				)
			);
			$order->save();
			return;
		}

		$invoice_no   = (string) $order->get_meta( static::invoice_number_meta_key() );
		$scheduled_at = '' !== static::scheduled_meta_key() ? (string) $order->get_meta( static::scheduled_meta_key() ) : '';

		if ( static::is_real_invoice_number( $invoice_no ) ) {
			static::invoke_invalid( $order, static::reason_for_status( $status ) );
			return;
		}

		if ( '' !== $scheduled_at && '' !== static::deferred_issue_hook_name() ) {
			$cancelled = false;
			if ( function_exists( 'as_unschedule_action' ) ) {
				$cancelled = (bool) as_unschedule_action( static::deferred_issue_hook_name(), [ $order_id ], 'mo-ectools' );
			}
			if ( '' !== static::scheduled_meta_key() ) {
				$order->delete_meta_data( static::scheduled_meta_key() );
			}
			$order->add_order_note(
				$cancelled
					? sprintf(
						/* translators: 1: provider label */
						__( '%1$s 發票延後開立排程已取消（訂單退款 / 取消）。', 'mo-ectools' ),
						static::provider_label()
					)
					: sprintf(
						/* translators: 1: provider label */
						__( '%1$s 發票延後開立排程取消失敗或排程已過期 — 請手動確認 %1$s 後台。', 'mo-ectools' ),
						static::provider_label()
					)
			);
			$order->save();
		}
	}

	protected static function reason_for_status( string $status ): string {
		return match ( $status ) {
			'refunded'  => __( '訂單退款（自動作廢）', 'mo-ectools' ),
			'cancelled' => __( '訂單取消（自動作廢）', 'mo-ectools' ),
			'failed'    => __( '訂單失敗（自動作廢）', 'mo-ectools' ),
			default     => __( '訂單狀態變更（自動作廢）', 'mo-ectools' ),
		};
	}
}
