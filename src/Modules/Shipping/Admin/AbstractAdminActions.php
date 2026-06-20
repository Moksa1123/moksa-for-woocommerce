<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Admin;

use MoksaWeb\Mowc\Modules\Shipping\AbstractProvider;

defined( 'ABSPATH' ) || exit;

abstract class AbstractAdminActions {

	protected AbstractProvider $provider;

	public function __construct( AbstractProvider $provider ) {
		$this->provider = $provider;
	}

	abstract public function get_bulk_action_labels(): array;

	abstract public function handle_bulk_action( string $action, array $order_ids ): string;

	public function init(): void {
		add_filter( 'bulk_actions-edit-shop_order', [ $this, 'register_bulk_actions' ] );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ $this, 'register_bulk_actions' ] );
		add_filter( 'handle_bulk_actions-edit-shop_order', [ $this, 'dispatch_bulk_action' ], 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ $this, 'dispatch_bulk_action' ], 10, 3 );

		if ( 'advanced' === self::get_ui_mode() ) {
			add_action( 'admin_footer', [ $this, 'inject_advanced_modal_button' ] );
			add_action( 'wp_ajax_moksafowo_shipping_unprinted_orders_' . $this->provider->provider_slug(), [ $this, 'ajax_get_unprinted_orders' ] );
		}
	}

	public function register_bulk_actions( array $actions ): array {
		$prefix = 'moksafowo_' . $this->provider->provider_slug() . '_';
		foreach ( $this->get_bulk_action_labels() as $key => $label ) {
			$actions[ $prefix . $key ] = $label;
		}
		return $actions;
	}

	public function dispatch_bulk_action( $redirect_to, string $action, array $order_ids ) {
		$prefix = 'moksafowo_' . $this->provider->provider_slug() . '_';
		if ( ! str_starts_with( $action, $prefix ) ) {
			return $redirect_to;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( '權限不足', 'mo-ectools' ), 403 );
		}

		$pure_action = substr( $action, strlen( $prefix ) );
		return $this->handle_bulk_action( $pure_action, $order_ids );
	}

	public function find_unprinted_orders( ?string $kind = null, ?string $carrier = null ): array {
		$orders = wc_get_orders(
			[
				'limit'      => apply_filters( 'moksafowo_shipping_bulk_query_limit', 100 ),
				'status'     => apply_filters( 'moksafowo_shipping_bulk_query_statuses', [ 'processing', 'on-hold' ] ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
				'meta_query' => [
					[
						'key'     => $this->provider->meta_key_trade_no(),
						'value'   => '',
						'compare' => '!=',
					],
					[
						'key'     => $this->provider->meta_key_ship_no(),
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		return array_values(
			array_filter(
				$orders,
				function ( $order ) use ( $kind, $carrier ) {
					foreach ( $order->get_shipping_methods() as $method ) {
						$mid = $method->get_method_id();
						if ( ! $this->provider->is_supported_method( $mid ) ) {
							continue;
						}
						if ( $kind && $this->provider->get_method_kind( $mid ) !== $kind ) {
							continue;
						}
						if ( $carrier && $this->provider->get_method_carrier( $mid ) !== $carrier ) {
							continue;
						}
						return true;
					}
					return false;
				}
			)
		);
	}

	public function inject_advanced_modal_button(): void {
	}

	public function ajax_get_unprinted_orders(): void {
		check_ajax_referer( 'moksafowo_shipping_bulk_print', 'security' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( '權限不足', 'mo-ectools' ), 403 );
		}

		$kind    = isset( $_POST['kind'] ) ? sanitize_text_field( wp_unslash( $_POST['kind'] ) ) : null;
		$carrier = isset( $_POST['carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['carrier'] ) ) : null;

		$orders = $this->find_unprinted_orders( $kind, $carrier );
		$data   = array_map(
			function ( $o ) {
				return [
					'id'       => $o->get_id(),
					'number'   => $o->get_order_number(),
					'customer' => $o->get_formatted_billing_full_name(),
					'method'   => $o->get_shipping_method(),
				];
			},
			$orders
		);

		wp_send_json_success( $data );
	}

	public static function get_ui_mode(): string {
		if ( 'yes' === get_option( 'moksafowo_shipping_bulk_print_mode_advanced', 'no' ) ) {
			return 'advanced';
		}
		$legacy = (string) get_option( 'moksafowo_shipping_bulk_print_ui_mode', 'simple' );
		return 'advanced' === $legacy ? 'advanced' : 'simple';
	}
}
