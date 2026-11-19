<?php

declare( strict_types=1 );

namespace Moksafowo\Refund;

use Moksafowo\Modules\Shared\Caps;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce > 退款待處理（設計 §八）：結果未知的退款、發票待處理或失敗的退款。
 * 每列可「執行發票動作」「略過（填原因）」；結果未知的可標記「已確認」並寫下查到的結果。
 */
final class PendingPage {

	public const SLUG = 'moksafowo-refunds';

	private const ACTION = 'moksafowo_refund_pending';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 60 );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
	}

	public static function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Refunds to review', 'moksa-for-woocommerce' ),
			__( 'Refunds to review', 'moksa-for-woocommerce' ),
			Caps::for( Caps::REFUND ),
			self::SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	private const OPEN_OPTION = 'moksafowo_refund_open_orders';

	/** 待處理訂單清單另存一個不自動載入的 option，列表與提示不必用 meta 查詢掃訂單表。 */
	public static function mark( int $order_id, bool $open ): void {
		// 兩張單同時更新清單會互相蓋掉：先拿清單鎖（最多等約 1 秒）。
		$tries = 0;
		$got   = Guard::lock( 0, 'openlist' );
		while ( ! $got && $tries < 10 ) {
			usleep( 100000 );
			++$tries;
			$got = Guard::lock( 0, 'openlist' );
		}
		wp_cache_delete( self::OPEN_OPTION, 'options' );
		$ids = array_map( 'absint', (array) get_option( self::OPEN_OPTION, [] ) );
		if ( in_array( $order_id, $ids, true ) !== $open ) {
			$ids = $open ? array_merge( $ids, [ $order_id ] ) : array_diff( $ids, [ $order_id ] );
			update_option( self::OPEN_OPTION, array_values( array_unique( $ids ) ), false );
		}
		Guard::unlock( 0, 'openlist' );
	}

	/** @return \WC_Order[] */
	public static function open_orders( int $limit = 100 ): array {
		$orders = [];
		foreach ( array_slice( array_reverse( array_map( 'absint', (array) get_option( self::OPEN_OPTION, [] ) ) ), 0, $limit ) as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order && 'yes' === $order->get_meta( Keys::REFUND_OPEN ) ) {
				$orders[] = $order;
			}
		}
		return $orders;
	}

	/** 一張單的待處理項目（給頁面與唯讀 Ability 共用）。 */
	public static function items( \WC_Order $order ): array {
		$items = [];
		foreach ( Guard::unknown_list( $order ) as $i => $row ) {
			$items[] = [
				'kind'    => 'unknown',
				'index'   => $i,
				'amount'  => (string) ( $row['amount'] ?? '' ),
				'time'    => (int) ( $row['time'] ?? 0 ),
				'message' => (string) ( $row['message'] ?? '' ),
			];
		}
		foreach ( $order->get_refunds() as $refund ) {
			$state = (string) $refund->get_meta( Keys::REFUND_INVOICE_STATE );
			if ( '' === $state ) {
				continue;
			}
			$items[] = [
				'kind'      => 'invoice',
				'refund_id' => $refund->get_id(),
				'amount'    => (string) $refund->get_amount(),
				'time'      => $refund->get_date_created() ? $refund->get_date_created()->getTimestamp() : 0,
				'state'     => $state,
				'detail'    => (string) $refund->get_meta( Keys::REFUND_INVOICE_REF ),
				'error'     => (string) $refund->get_meta( Keys::REFUND_INVOICE_ERROR ),
				'payment'   => (string) $refund->get_meta( Keys::REFUND_STATE ),
			];
		}
		return $items;
	}

	public static function state_label( string $state ): string {
		return match ( $state ) {
			'todo_void'            => __( 'Invoice to void', 'moksa-for-woocommerce' ),
			'todo_allowance'       => __( 'Allowance to issue', 'moksa-for-woocommerce' ),
			'todo_cancel_schedule' => __( 'Scheduled invoice to cancel', 'moksa-for-woocommerce' ),
			'todo_manual'          => __( 'Handle manually', 'moksa-for-woocommerce' ),
			'failed'               => __( 'Failed', 'moksa-for-woocommerce' ),
			'done'                 => __( 'Done', 'moksa-for-woocommerce' ),
			'skipped'              => __( 'Not needed', 'moksa-for-woocommerce' ),
			default                => $state,
		};
	}

	public static function render(): void {
		if ( ! Caps::user_can( Caps::REFUND ) ) {
			wp_die( esc_html__( 'You do not have permission to refund orders.', 'moksa-for-woocommerce' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Refunds to review', 'moksa-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'Refunds whose payment result could not be confirmed, and refunds whose e-invoice still needs voiding or an allowance. The money side of a refund is never undone because of the invoice.', 'moksa-for-woocommerce' ) . '</p>';

		$orders = self::open_orders();
		if ( ! $orders ) {
			echo '<p><strong>' . esc_html__( 'Nothing to review.', 'moksa-for-woocommerce' ) . '</strong></p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Order', 'moksa-for-woocommerce' ) . '</th><th>' . esc_html__( 'Amount', 'moksa-for-woocommerce' ) . '</th><th>' . esc_html__( 'Status', 'moksa-for-woocommerce' ) . '</th><th>' . esc_html__( 'Details', 'moksa-for-woocommerce' ) . '</th><th>' . esc_html__( 'Actions', 'moksa-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $orders as $order ) {
			foreach ( self::items( $order ) as $item ) {
				if ( 'invoice' === $item['kind'] && in_array( $item['state'], [ 'done', 'skipped' ], true ) ) {
					continue;
				}
				echo '<tr><td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
				echo '<td>' . wp_kses_post( wc_price( (float) $item['amount'], [ 'currency' => $order->get_currency() ] ) ) . '</td>';
				if ( 'unknown' === $item['kind'] ) {
					echo '<td>' . esc_html__( 'Payment result unconfirmed', 'moksa-for-woocommerce' ) . '</td><td>' . esc_html( $item['message'] ) . '<br>' . esc_html__( 'Check the payment provider dashboard: if the money was refunded there, record a manual refund on the order after confirming here.', 'moksa-for-woocommerce' ) . '</td><td>';
					self::form(
						[
							'do'       => 'resolve',
							'order_id' => $order->get_id(),
							'index'    => $item['index'],
						],
						__( 'Checked, unblock refunds', 'moksa-for-woocommerce' ),
						true
					);
				} else {
					echo '<td>' . esc_html( self::state_label( $item['state'] ) ) . '</td><td>' . esc_html( trim( ( 'todo_allowance' === $item['state'] ? 'NT$' . $item['detail'] : $item['detail'] ) . ' ' . $item['error'] ) ) . '</td><td>';
					if ( in_array( $item['state'], [ 'todo_void', 'todo_allowance', 'todo_cancel_schedule', 'failed' ], true ) ) {
						self::form(
							[
								'do'        => 'run',
								'refund_id' => $item['refund_id'],
							],
							__( 'Run the invoice step', 'moksa-for-woocommerce' ),
							false
						);
					}
					self::form(
						[
							'do'        => 'skip',
							'refund_id' => $item['refund_id'],
						],
						__( 'Mark as handled', 'moksa-for-woocommerce' ),
						true
					);
				}
				echo '</td></tr>';
			}
		}
		echo '</tbody></table></div>';
	}

	private static function form( array $fields, string $label, bool $with_note ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 4px 0">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		wp_nonce_field( self::ACTION );
		if ( $with_note ) {
			echo '<input type="text" name="note" required placeholder="' . esc_attr__( 'What did you find or do?', 'moksa-for-woocommerce' ) . '"> ';
		}
		echo '<button class="button">' . esc_html( $label ) . '</button></form>';
	}

	public static function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! Caps::user_can( Caps::REFUND ) ) {
			wp_die( esc_html__( 'You do not have permission to refund orders.', 'moksa-for-woocommerce' ) );
		}
		$do   = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$note = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );

		if ( 'resolve' === $do ) {
			$order = wc_get_order( absint( wp_unslash( $_POST['order_id'] ?? 0 ) ) );
			$index = absint( wp_unslash( $_POST['index'] ?? 0 ) );
			if ( $order instanceof \WC_Order && '' !== $note && Guard::lock( $order->get_id() ) ) {
				$list = Guard::unknown_list( $order );
				if ( isset( $list[ $index ] ) ) {
					unset( $list[ $index ] );
					$list ? $order->update_meta_data( Keys::REFUND_UNKNOWN, array_values( $list ) ) : $order->delete_meta_data( Keys::REFUND_UNKNOWN );
					/* translators: 1: user name, 2: note */
					$order->add_order_note( sprintf( __( 'Unconfirmed refund checked by %1$s: %2$s', 'moksa-for-woocommerce' ), wp_get_current_user()->display_name, $note ) );
					$order->save();
				}
				Guard::unlock( $order->get_id() );
				InvoiceSync::refresh_open( wc_get_order( $order->get_id() ) );
			}
		} else {
			$refund = wc_get_order( absint( wp_unslash( $_POST['refund_id'] ?? 0 ) ) );
			if ( $refund instanceof \WC_Order_Refund ) {
				if ( 'run' === $do ) {
					if ( 'failed' === (string) $refund->get_meta( Keys::REFUND_INVOICE_STATE ) ) {
						// 失敗後店長要重試：照最新的訂單狀態重新決定作廢或折讓。
						$order           = wc_get_order( $refund->get_parent_id() );
						[ $state, $ref ] = InvoiceSync::plan( $order, $refund );
						$refund->update_meta_data( Keys::REFUND_INVOICE_STATE, $state );
						$refund->update_meta_data( Keys::REFUND_INVOICE_REF, $ref );
						$refund->update_meta_data( Keys::REFUND_INVOICE_TRIES, 0 );
						$refund->save();
					}
					InvoiceSync::run( $refund->get_id() );
				} elseif ( 'skip' === $do && '' !== $note ) {
					InvoiceSync::skip( $refund, $note );
				}
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public static function notice(): void {
		if ( ! Caps::user_can( Caps::REFUND ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, self::SLUG ) ) {
			return;
		}
		$count = count( self::open_orders( 50 ) );
		if ( 0 === $count ) {
			return;
		}
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			/* translators: %d: number of orders */
			esc_html( sprintf( _n( '%d order has a refund that needs review.', '%d orders have refunds that need review.', $count, 'moksa-for-woocommerce' ), $count ) ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Review refunds', 'moksa-for-woocommerce' )
		);
	}
}
