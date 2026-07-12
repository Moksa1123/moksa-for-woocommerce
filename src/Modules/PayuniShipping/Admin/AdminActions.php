<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Admin;

use Moksafowo\Modules\PayuniShipping\Utils\ShipType;
use Moksafowo\Modules\Shipping\Admin\AbstractAdminActions;
use Moksafowo\Modules\Shipping\AbstractProvider;

defined( 'ABSPATH' ) || exit;

final class AdminActions extends AbstractAdminActions {

	public function __construct() {
		parent::__construct(
			new class() extends AbstractProvider {
				public function provider_slug(): string {
					return 'payuni'; }
				public function provider_name(): string {
					return 'PAYUNi'; }
				public function meta_key_trade_no(): string {
					return '_moksafowo_payuni_shipping_trade_no'; }
				public function meta_key_ship_no(): string {
					return '_moksafowo_payuni_shipping_ship_no'; }
				public function is_supported_method( string $method_id ): bool {
					return str_starts_with( $method_id, 'moksafowo_payuni_shipping_' );
				}
				public function get_method_kind( string $method_id ): string {
					return ''; }
				public function get_method_carrier( string $method_id ): string {
					return ''; }
				public function boot(): void {}
			}
		);
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
			return admin_url( 'admin.php?page=wc-orders&moksafowo_payuni_print=invalid' );
		}

		// 只取已 ShipTradeNo 但無 ShipNo 的訂單（已建單未列印）
		$valid = [];
		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( (int) $oid );
			if ( ! $order ) {
				continue;
			}
			$trade_no = $order->get_meta( '_moksafowo_payuni_shipping_trade_no' );
			$ship_no  = $order->get_meta( '_moksafowo_payuni_shipping_ship_no' );
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
			return admin_url( 'admin.php?page=wc-orders&moksafowo_payuni_print=no_valid' );
		}

		$print_url = admin_url(
			sprintf(
				'admin-ajax.php?action=moksafowo_payuni_shipping_print_label&orderids=%s&service=%s&security=%s',
				implode( ',', $valid ),
				$ship_type,
				wp_create_nonce( 'moksafowo-payuni-shipping-order' )
			)
		);

		return $print_url;
	}
}
