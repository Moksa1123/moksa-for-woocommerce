<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * RY Tools 的三個超商訂單狀態。RY 停用後沒人註冊，用這些狀態的訂單會從訂單列表消失；
 * 這裡在 RY 沒有註冊時補上，名稱與「視為已付款」都跟 RY 一致。
 */
final class LegacyStatuses {

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 20 );
		add_filter( 'wc_order_statuses', [ __CLASS__, 'add_to_list' ], 20 );
		add_filter( 'woocommerce_order_is_paid_statuses', [ __CLASS__, 'add_paid' ], 20 );
	}

	/** @return array<string,string> slug（含 wc- 前綴）=> 顯示名稱 */
	private static function statuses(): array {
		return [
			'wc-ry-at-cvs'       => _x( 'Awaiting pickup at store', 'Order status', 'moksa-for-woocommerce' ),
			'wc-ry-out-cvs'      => _x( 'Not picked up at store', 'Order status', 'moksa-for-woocommerce' ),
			'wc-ry-transporting' => _x( 'In transit', 'Order status', 'moksa-for-woocommerce' ),
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
