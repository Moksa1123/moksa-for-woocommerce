<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Statuses;

use Moksafowo\Http\Input;

defined( 'ABSPATH' ) || exit;

/**
 * 舊外掛的出貨訂單狀態：RY Tools 的三個超商狀態、woomp 的「已出貨」「配送中」。
 * 舊外掛停用後沒人註冊，用這些狀態的訂單會從訂單列表消失；這裡在舊外掛沒有註冊時補上，
 * 名稱與「視為已付款」都跟原外掛一致，並可批次轉成 Moksa 狀態（不寄信）。
 */
final class LegacyStatuses {

	private const BULK_ACTION = 'moksafowo_convert_ry_status';

	/** 舊狀態 => Moksa 狀態（都不含 wc- 前綴）。Moksa 沒有「配送中」，woomp 的配送中也轉成已出貨。 */
	private const CONVERT = [
		'ry-at-cvs'       => 'moksa-cvs-arrived',
		'ry-out-cvs'      => 'moksa-unclaimed',
		'ry-transporting' => 'moksa-shipped',
		'wmp-shipped'     => 'moksa-shipped',
		'wmp-in-transit'  => 'moksa-shipped',
	];

	/** 目前有幾張訂單停在舊狀態（批次轉換前先看數量）。 */
	public static function count(): int {
		$count = 0;
		foreach ( array_keys( self::CONVERT ) as $status ) {
			$count += (int) wc_orders_count( $status );
		}
		return $count;
	}

	/** @return array<string,string> 舊狀態 => Moksa 狀態。 */
	public static function conversions(): array {
		return self::CONVERT;
	}

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 20 );
		add_filter( 'wc_order_statuses', [ __CLASS__, 'add_to_list' ], 20 );
		add_filter( 'woocommerce_order_is_paid_statuses', [ __CLASS__, 'add_paid' ], 20 );

		// 轉成 Moksa 狀態要 Moksa 自己的訂單狀態有開。
		if ( is_admin() && \Moksafowo\Settings\AdvancedSections::is_on( \Moksafowo\Settings\AdvancedSections::ORDER_STATUSES ) ) {
			add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'add_bulk_action' ], 30 );
			add_filter( 'bulk_actions-edit-shop_order', [ __CLASS__, 'add_bulk_action' ], 30 );
			add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
			add_filter( 'handle_bulk_actions-edit-shop_order', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
			add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
		}
	}

	/**
	 * 只有還有 RY 狀態的訂單時才出現。
	 *
	 * @param array<string,string> $actions 批次動作。
	 * @return array<string,string>
	 */
	public static function add_bulk_action( array $actions ): array {
		if ( self::count() > 0 ) {
			$actions[ self::BULK_ACTION ] = __( 'Switch earlier plugin statuses to Moksa statuses', 'moksa-for-woocommerce' );
		}
		return $actions;
	}

	/**
	 * 只動 RY 狀態的訂單；轉換時不寄任何信，避免舊訂單的顧客又收到到店／出貨通知。
	 *
	 * @param string $redirect_to 完成後導回的網址。
	 * @param string $action      批次動作。
	 * @param int[]  $ids         勾選的訂單。
	 */
	public static function handle_bulk_action( $redirect_to, $action, $ids ) {
		if ( self::BULK_ACTION !== $action ) {
			return $redirect_to;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-for-woocommerce' ), 403 );
		}
		$done = 0;
		add_filter( 'pre_wp_mail', '__return_false', 999 );
		try {
			foreach ( (array) $ids as $id ) {
				$order = wc_get_order( absint( $id ) );
				if ( ! $order instanceof \WC_Order || ! isset( self::CONVERT[ $order->get_status() ] ) ) {
					continue;
				}
				$order->update_status(
					self::CONVERT[ $order->get_status() ],
					__( 'Switched from an earlier plugin\'s status (no email sent).', 'moksa-for-woocommerce' )
				);
				++$done;
			}
		} finally {
			// 中途出錯也要拿掉，不然整個請求後面的信都寄不出去。
			remove_filter( 'pre_wp_mail', '__return_false', 999 );
		}
		return add_query_arg( 'moksafowo_ry_converted', $done, $redirect_to );
	}

	public static function notice(): void {
		if ( ! Input::has_get( 'moksafowo_ry_converted' ) ) {
			return;
		}
		$count = absint( Input::get( 'moksafowo_ry_converted' ) );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of orders */
					_n( '%d order was switched to a Moksa status.', '%d orders were switched to Moksa statuses.', $count, 'moksa-for-woocommerce' ),
					$count
				)
			)
		);
	}

	/** @return array<string,string> slug（含 wc- 前綴）=> 顯示名稱 */
	private static function statuses(): array {
		return [
			'wc-ry-at-cvs'       => _x( 'Awaiting pickup at store', 'Order status', 'moksa-for-woocommerce' ),
			'wc-ry-out-cvs'      => _x( 'Not picked up at store', 'Order status', 'moksa-for-woocommerce' ),
			'wc-ry-transporting' => _x( 'In transit', 'Order status', 'moksa-for-woocommerce' ),
			'wc-wmp-shipped'     => _x( 'Shipped (woomp)', 'Order status', 'moksa-for-woocommerce' ),
			'wc-wmp-in-transit'  => _x( 'Out for delivery (woomp)', 'Order status', 'moksa-for-woocommerce' ),
		];
	}

	/** @return array<string,array<int|string,mixed>> */
	private static function label_counts(): array {
		return [
			/* translators: %s: number of orders */
			'wc-ry-at-cvs'       => _n_noop( 'Awaiting pickup at store <span class="count">(%s)</span>', 'Awaiting pickup at store <span class="count">(%s)</span>', 'moksa-for-woocommerce' ),
			/* translators: %s: number of orders */
			'wc-ry-out-cvs'      => _n_noop( 'Not picked up at store <span class="count">(%s)</span>', 'Not picked up at store <span class="count">(%s)</span>', 'moksa-for-woocommerce' ),
			/* translators: %s: number of orders */
			'wc-ry-transporting' => _n_noop( 'In transit <span class="count">(%s)</span>', 'In transit <span class="count">(%s)</span>', 'moksa-for-woocommerce' ),
			/* translators: %s: number of orders */
			'wc-wmp-shipped'     => _n_noop( 'Shipped (woomp) <span class="count">(%s)</span>', 'Shipped (woomp) <span class="count">(%s)</span>', 'moksa-for-woocommerce' ),
			/* translators: %s: number of orders */
			'wc-wmp-in-transit'  => _n_noop( 'Out for delivery (woomp) <span class="count">(%s)</span>', 'Out for delivery (woomp) <span class="count">(%s)</span>', 'moksa-for-woocommerce' ),
		];
	}

	public static function register(): void {
		foreach ( self::statuses() as $slug => $label ) {
			if ( null !== get_post_status_object( $slug ) ) {
				continue;
			}
			register_post_status(
				$slug,
				[
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => self::label_counts()[ $slug ],
				]
			);
		}
	}

	/**
	 * @param array<string,string> $statuses WooCommerce 的訂單狀態清單。
	 * @return array<string,string>
	 */
	public static function add_to_list( array $statuses ): array {
		foreach ( self::statuses() as $slug => $label ) {
			if ( ! isset( $statuses[ $slug ] ) ) {
				$statuses[ $slug ] = $label;
			}
		}
		return $statuses;
	}

	/**
	 * @param string[] $statuses 視為已付款的狀態（不含 wc- 前綴）。
	 * @return string[]
	 */
	public static function add_paid( array $statuses ): array {
		foreach ( array_keys( self::statuses() ) as $slug ) {
			$bare = substr( $slug, 3 );
			if ( ! in_array( $bare, $statuses, true ) ) {
				$statuses[] = $bare;
			}
		}
		return $statuses;
	}
}
