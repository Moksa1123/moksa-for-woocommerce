<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\NewebpayShipping;

use Moksafowo\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'newebpay_shipping';
	}

	public function label(): string {
		return __( 'NewebPay shipping — convenience store pickup, at its best alongside the NewebPay convenience store code', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'shipping';
	}

	public function name(): string {
		return __( 'NewebPay shipping', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( 'Convenience store pickup — 7-ELEVEN, FamilyMart, Hi-Life and OK Mart', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( '7-ELEVEN pickup', 'moksa-for-woocommerce' ),
			__( 'FamilyMart pickup', 'moksa-for-woocommerce' ),
			__( 'Hi-Life pickup', 'moksa-for-woocommerce' ),
			__( 'OK Mart pickup', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'newebpay-shipping';
	}

	public function boot(): void {
		add_filter( 'woocommerce_shipping_methods', [ __CLASS__, 'register_methods' ] );

		add_action( 'woocommerce_api_moksafowo_newebpay_shipping_status', [ Api\IpnHandler::class, 'handle' ] );
		add_filter( 'woocommerce_default_address_fields', [ __CLASS__, 'relax_cvs_required_fields' ] );
		// 批次列印走 NPA-B54（PickupNotice 保留為無建單 fallback）
		add_filter( 'moksafowo_shipping_batch_print_providers', [ __CLASS__, 'register_batch_print' ] );
		Operations\PickupNotice::init();
		add_action( 'wp_ajax_moksafowo_newebpay_shipping_create', [ __CLASS__, 'ajax_create_shipment' ] );
		add_action( 'wp_ajax_moksafowo_newebpay_shipping_query', [ __CLASS__, 'ajax_query_shipment' ] );
		add_action( 'wp_ajax_moksafowo_newebpay_shipping_trace', [ __CLASS__, 'ajax_trace_shipment' ] );
		Frontend\StoreSelector::init();
		add_filter( \Moksafowo\Modules\Shipping\Admin\CvsStoreEditor::PROVIDERS_FILTER, [ __CLASS__, 'register_cvs_store_editor' ] );

		if ( is_admin() ) {
			Admin\OrderMetaBox::init();
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $providers Registered providers.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_cvs_store_editor( array $providers ): array {
		$providers['newebpay'] = [
			'label'    => __( 'NewebPay convenience store pickup', 'moksa-for-woocommerce' ),
			'methods'  => [ 'moksafowo_newebpay_shipping_cvs' => 'moksafowo_newebpay_shipping_cvs' ],
			'meta'     => [
				'id'      => \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_ID,
				'name'    => \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_NAME,
				'address' => \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_ADDRESS,
			],
			'open_map' => [ Frontend\StoreSelector::class, 'admin_map_payload' ],
			// 建單讀共用鍵，但模組自己的鍵也有人讀（PickupNotice / 客戶端顯示），一起寫才不會兩邊對不上
			'sync'     => static function ( \WC_Order $order, array $store ): void {
				$order->update_meta_data( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_STORE_ID, $store['id'] );
				$order->update_meta_data( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_STORE_NAME, $store['name'] );
				$order->update_meta_data( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_STORE_ADDR, $store['address'] );
			},
		];
		return $providers;
	}

	public static function register_batch_print( array $providers ): array {
		$titles                    = [
			'moksafowo_newebpay_shipping_cvs' => __( 'NewebPay convenience store pickup', 'moksa-for-woocommerce' ),
		];
		$counter                   = static fn( \WC_Order $o ): int => Operations\PrintLabel::record_count( $o );
		$providers['newebpay-cvs'] = [
			'label'          => __( 'NewebPay shipment', 'moksa-for-woocommerce' ),
			'category'       => 'cvs',
			'method_ids'     => $titles,
			'handler'        => [ Operations\PrintLabel::class, 'render' ],
			'record_counter' => $counter,
			// NPA-B54 回傳統一 PDF，A6 規格無效
			'paper_modes'    => [ '1' ],
		];
		return $providers;
	}

	public static function ajax_create_shipment(): void {
		check_ajax_referer( 'moksafowo_newebpay_shipping_create' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ] );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order does not exist.', 'moksa-for-woocommerce' ) ] );
		}
		$result = Operations\CreateShipment::run( $order );
		if ( $result['ok'] ) {
			wp_send_json_success(
				[
					'message' => sprintf(
						/* translators: %s: lgs_no */
						__( 'NewebPay shipment created, number %s', 'moksa-for-woocommerce' ),
						$result['lgs_no']
					),
				]
			);
		}
		wp_send_json_error( [ 'message' => $result['message'] ] );
	}

	public static function ajax_query_shipment(): void {
		check_ajax_referer( 'moksafowo_newebpay_shipping_query' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ] );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order does not exist.', 'moksa-for-woocommerce' ) ] );
		}
		$mtn = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_MERCHANT_ORDER_NO );
		if ( '' === $mtn ) {
			wp_send_json_error( [ 'message' => __( 'No shipment has been created for this order yet, so there is no merchant order number to look up.', 'moksa-for-woocommerce' ) ] );
		}
		$result = Api\ShippingRequest::query_shipment( $mtn );
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			wp_send_json_error( [ 'message' => sprintf( __( 'The lookup failed: %s', 'moksa-for-woocommerce' ), $result['message'] ) ] );
		}
		$data   = $result['data'] ?? [];
		$retld  = (string) ( $data['Retld'] ?? $data['RetId'] ?? '' );
		$mapped = '' !== $retld ? Operations\StatusMapper::map( $retld ) : null;
		if ( null !== $mapped ) {
			$order->update_meta_data( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_STATUS, $mapped['label'] );
			$order->add_order_note(
				sprintf(
				/* translators: %s: status label */
					__( 'NewebPay shipping lookup: %s', 'moksa-for-woocommerce' ),
					$mapped['label']
				)
			);
			$order->save();
		}
		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %s: status */
					__( 'Lookup succeeded — %s', 'moksa-for-woocommerce' ),
					null !== $mapped ? $mapped['label'] : ( $data['RetString'] ?? 'OK' )
				),
			]
		);
	}

	public static function ajax_trace_shipment(): void {
		check_ajax_referer( 'moksafowo_newebpay_shipping_trace' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ] );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order does not exist.', 'moksa-for-woocommerce' ) ] );
		}
		$mtn = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_MERCHANT_ORDER_NO );
		if ( '' === $mtn ) {
			wp_send_json_error( [ 'message' => __( 'No shipment has been created for this order yet.', 'moksa-for-woocommerce' ) ] );
		}
		$result = Api\ShippingRequest::trace( $mtn );
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			wp_send_json_error( [ 'message' => sprintf( __( 'Tracking failed: %s', 'moksa-for-woocommerce' ), $result['message'] ) ] );
		}
		$data    = $result['data'] ?? [];
		$history = is_array( $data['History'] ?? null ) ? $data['History'] : [];
		$out     = [];
		foreach ( $history as $h ) {
			$retld  = (string) ( $h['Retld'] ?? $h['RetId'] ?? '' );
			$mapped = '' !== $retld ? Operations\StatusMapper::map( $retld ) : null;
			$out[]  = [
				'event_time' => (string) ( $h['EventTime'] ?? '' ),
				'label'      => null !== $mapped ? $mapped['label'] : (string) ( $h['RetString'] ?? '' ),
				'retld'      => $retld,
			];
		}
		wp_send_json_success( [ 'history' => $out ] );
	}

	public static function method_map(): array {
		return [
			'moksafowo_newebpay_shipping_cvs' => Methods\Cvs::class,
		];
	}

	public static function register_methods( array $methods ): array {
		foreach ( self::method_map() as $id => $class ) {
			$methods[ $id ] = $class;
		}
		return $methods;
	}

	public static function relax_cvs_required_fields( array $fields ): array {
		if ( ! function_exists( 'wc_get_chosen_shipping_method_ids' ) ) {
			return $fields;
		}
		$chosen = wc_get_chosen_shipping_method_ids();
		foreach ( $chosen as $method ) {
			if ( str_starts_with( (string) $method, 'moksafowo_newebpay_shipping_' ) ) {
				if ( isset( $fields['phone'] ) ) {
					$fields['phone']['required'] = false;
				}
				break;
			}
		}
		return $fields;
	}
}
