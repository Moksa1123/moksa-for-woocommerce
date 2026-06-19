<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\NewebpayShipping;

use MoksaWeb\Mowc\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'newebpay_shipping';
	}

	public function label(): string {
		return __( '藍新物流 — 超商取貨（搭配藍新超商代碼付款效果最佳）', 'mo-ectools' );
	}

	public function category(): string {
		return 'shipping';
	}

	public function name(): string {
		return __( '藍新物流', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '超商取貨 — 7-11 / 全家 / 萊爾富 / OK', 'mo-ectools' );
	}

	public function methods(): array {
		return [
			__( '7-11 取貨', 'mo-ectools' ),
			__( '全家取貨', 'mo-ectools' ),
			__( '萊爾富取貨', 'mo-ectools' ),
			__( 'OK 取貨', 'mo-ectools' ),
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

		if ( is_admin() ) {
			Admin\OrderMetaBox::init();
		}
	}

	public static function register_batch_print( array $providers ): array {
		$titles                    = [
			'moksafowo_newebpay_shipping_cvs' => __( '藍新超商取貨', 'mo-ectools' ),
		];
		$counter                   = static fn( \WC_Order $o ): int => Operations\PrintLabel::record_count( $o );
		$providers['newebpay-cvs'] = [
			'label'          => __( '藍新 物流託運單', 'mo-ectools' ),
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
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ] );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( '訂單不存在。', 'mo-ectools' ) ] );
		}
		$result = Operations\CreateShipment::run( $order );
		if ( $result['ok'] ) {
			wp_send_json_success(
				[
					'message' => sprintf(
						/* translators: %s: lgs_no */
						__( '藍新物流單建立成功（單號 %s）', 'mo-ectools' ),
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
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ] );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( '訂單不存在。', 'mo-ectools' ) ] );
		}
		$mtn = (string) $order->get_meta( \MoksaWeb\Mowc\Order\Meta\Keys::NEWEBPAY_SHIPPING_MERCHANT_ORDER_NO );
		if ( '' === $mtn ) {
			wp_send_json_error( [ 'message' => __( '訂單尚未建單，無 MerchantOrderNo 可查詢。', 'mo-ectools' ) ] );
		}
		$result = Api\ShippingRequest::query_shipment( $mtn );
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			wp_send_json_error( [ 'message' => sprintf( __( '查詢失敗：%s', 'mo-ectools' ), $result['message'] ) ] );
		}
		$data   = $result['data'] ?? [];
		$retld  = (string) ( $data['Retld'] ?? $data['RetId'] ?? '' );
		$mapped = '' !== $retld ? Operations\StatusMapper::map( $retld ) : null;
		if ( null !== $mapped ) {
			$order->update_meta_data( \MoksaWeb\Mowc\Order\Meta\Keys::NEWEBPAY_SHIPPING_STATUS, $mapped['label'] );
			$order->add_order_note(
				sprintf(
				/* translators: %s: status label */
					__( '查詢藍新物流：%s', 'mo-ectools' ),
					$mapped['label']
				)
			);
			$order->save();
		}
		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %s: status */
					__( '查詢成功 — %s', 'mo-ectools' ),
					null !== $mapped ? $mapped['label'] : ( $data['RetString'] ?? 'OK' )
				),
			]
		);
	}

	public static function ajax_trace_shipment(): void {
		check_ajax_referer( 'moksafowo_newebpay_shipping_trace' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ] );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( '訂單不存在。', 'mo-ectools' ) ] );
		}
		$mtn = (string) $order->get_meta( \MoksaWeb\Mowc\Order\Meta\Keys::NEWEBPAY_SHIPPING_MERCHANT_ORDER_NO );
		if ( '' === $mtn ) {
			wp_send_json_error( [ 'message' => __( '訂單尚未建單。', 'mo-ectools' ) ] );
		}
		$result = Api\ShippingRequest::trace( $mtn );
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			wp_send_json_error( [ 'message' => sprintf( __( '追蹤失敗：%s', 'mo-ectools' ), $result['message'] ) ] );
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
