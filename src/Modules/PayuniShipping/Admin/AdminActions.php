<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Admin;

use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShipType;
use MoksaWeb\Mowc\Modules\Shipping\Admin\AbstractAdminActions;
use MoksaWeb\Mowc\Modules\Shipping\AbstractProvider;

defined( 'ABSPATH' ) || exit;

final class AdminActions extends AbstractAdminActions {

	public function __construct() {
		parent::__construct( new class extends AbstractProvider {
	public function provider_slug(): string { return 'payuni'; }
	public function provider_name(): string { return 'PAYUNi'; }
	public function meta_key_trade_no(): string { return '_mo_payuni_shipping_trade_no'; }
	public function meta_key_ship_no(): string { return '_mo_payuni_shipping_ship_no'; }
	public function is_supported_method( string $method_id ): bool {
				return str_starts_with( $method_id, 'mo_payuni_shipping_' );
			}
	public function get_method_kind( string $method_id ): string { return ''; }
	public function get_method_carrier( string $method_id ): string { return ''; }
	public function boot(): void {}
		} );
	}

	public function get_bulk_action_labels(): array {
		return [
			'print_711'  => __( '批次列印超商取貨標籤', 'mo-ectools' ),
			'print_tcat' => __( '批次列印黑貓宅配標籤', 'mo-ectools' ),
		];
	}

	public function handle_bulk_action( string $action, array $order_ids ): string {
		$ship_type = match ( $action ) {
			'print_711'  => ShipType::SEVEN,
			'print_tcat' => ShipType::TCAT,
			default      => '',
		};

		if ( '' === $ship_type || empty( $order_ids ) ) {
			return admin_url( 'admin.php?page=wc-orders&mo_payuni_print=invalid' );
		}

		// 只取已 ShipTradeNo 但無 ShipNo 的訂單（已建單未列印）
		$valid = [];
		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( (int) $oid );
			if ( ! $order ) {
				continue;
			}
			$trade_no = $order->get_meta( '_mo_payuni_shipping_trade_no' );
			$ship_no  = $order->get_meta( '_mo_payuni_shipping_ship_no' );
			if ( empty( $trade_no ) ) {
				continue;
			}
			// TCat 已印過不重印；CVS 可重印
			if ( ! empty( $ship_no ) && ShipType::TCAT === $ship_type ) {
				continue;
			}
			$valid[] = (int) $oid;
		}

		if ( empty( $valid ) ) {
			return admin_url( 'admin.php?page=wc-orders&mo_payuni_print=no_valid' );
		}

		// 組 print URL — 用既有 mo_payuni_shipping_print_label endpoint
		$print_url = admin_url( sprintf(
			'admin-ajax.php?action=mo_payuni_shipping_print_label&orderids=%s&service=%s&security=%s',
			implode( ',', $valid ),
			$ship_type,
			wp_create_nonce( 'payuni-shipping-order' )
		) );

		// WC bulk action 的 redirect 是同分頁。開 print URL 後使用者自己 back 回來。
		return $print_url;
	}
}
