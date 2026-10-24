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
		add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'validate_classic_phone' ], 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'validate_block_phone' ], 20 );
		// 批次列印走 NPA-B54（PickupNotice 保留為無建單 fallback）
		add_filter( 'moksafowo_shipping_batch_print_providers', [ __CLASS__, 'register_batch_print' ] );
		Operations\PickupNotice::init();
		add_action( 'wp_ajax_moksafowo_newebpay_shipping_create', [ __CLASS__, 'ajax_create_shipment' ] );
		add_action( 'wp_ajax_moksafowo_newebpay_shipping_query', [ __CLASS__, 'ajax_query_shipment' ] );
		add_action( 'wp_ajax_moksafowo_newebpay_shipping_trace', [ __CLASS__, 'ajax_trace_shipment' ] );
		Frontend\StoreSelector::init();
		Frontend\CustomerOrderView::init();
		add_filter( 'woocommerce_order_get_formatted_shipping_address', [ __CLASS__, 'store_as_shipping_address' ], 10, 3 );
		Emails\EmailTrackingProvider::init();
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
			'label'            => __( 'NewebPay convenience store pickup', 'moksa-for-woocommerce' ),
			'methods'          => [ 'moksafowo_newebpay_shipping_cvs' => 'moksafowo_newebpay_shipping_cvs' ],
			'meta'             => [
				'id'      => \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_ID,
				'name'    => \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_NAME,
				'address' => \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_ADDRESS,
			],
			'open_map'         => [ Frontend\StoreSelector::class, 'admin_map_payload' ],
			'session_store_id' => [ Frontend\StoreSelector::class, 'session_store_id' ],
			// 建單讀共用鍵，但模組自己的鍵也有人讀（PickupNotice / 客戶端顯示），一起寫才不會兩邊對不上
			'sync'             => static function ( \WC_Order $order, array $store ): void {
				$order->update_meta_data( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_STORE_ID, $store['id'] );
				$order->update_meta_data( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_STORE_NAME, $store['name'] );
				$order->update_meta_data( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_STORE_ADDR, $store['address'] );
			},
		];
		return $providers;
	}

	public static function register_batch_print( array $providers ): array {
		$titles  = [
			'moksafowo_newebpay_shipping_cvs' => __( 'NewebPay convenience store pickup', 'moksa-for-woocommerce' ),
		];
		$counter = static fn( \WC_Order $o ): int => Operations\PrintLabel::record_count( $o );
		// 藍新沒有 records 陣列，只有單鍵 —— 這裡把它包成一筆虛擬記錄，
		// 刪除就是清掉那組鍵（只清站上的，不會通知藍新作廢）
		$lister  = static function ( \WC_Order $o ): array {
			$no = (string) $o->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_LGS_NO );
			if ( '' === $no ) {
				return [];
			}
			return [
				[
					'id'       => $no,
					'tracking' => $no,
					'note'     => (string) $o->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_STATUS ),
				],
			];
		};
		$deleter = static function ( \WC_Order $o, string $id ): bool {
			if ( (string) $o->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_LGS_NO ) !== $id ) {
				return false;
			}
			foreach ( [
				\Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_LGS_NO,
				\Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_LGS_TYPE,
				\Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_MERCHANT_ORDER_NO,
				\Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_SHIP_TYPE,
				\Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_TRADE_TYPE,
				\Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_STATUS,
			] as $k ) {
				$o->delete_meta_data( $k );
			}
			$o->save();
			return true;
		};

		$providers['newebpay-cvs'] = [
			'label'          => __( 'NewebPay shipment', 'moksa-for-woocommerce' ),
			'category'       => 'cvs',
			'method_ids'     => $titles,
			'handler'        => [ Operations\PrintLabel::class, 'render' ],
			'record_counter' => $counter,
			'record_lister'  => $lister,
			'record_deleter' => $deleter,
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
			// 通知漏送或晚到時，商家按「查詢」要讓訂單狀態跟查到的貨態一致（與貨態通知同一套規則）。
			$target = (string) $mapped['wc_status'];
			if ( '' !== $target
				&& \Moksafowo\Modules\Shipping\Statuses\Registrar::is_usable( $target )
				&& \Moksafowo\Modules\Shipping\Statuses\StatusGuard::may_transition( $order->get_status(), $target ) ) {
				$order->update_status( $target );
				if ( str_starts_with( $target, 'moksa-' ) ) {
					do_action( 'moksafowo_shipping_status_' . $target . '_notification', $order->get_id(), $order );
				}
			}
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

	/**
	 * 藍新超商取貨的到店簡訊靠收件人手機（UserTel 必填，09 開頭 10 碼）。原本這裡把電話改成選填，
	 * 顧客不填也能結帳，到建單才被藍新拒絕。保留這個 filter 但不再放寬電話。
	 */
	public static function relax_cvs_required_fields( array $fields ): array {
		return $fields;
	}

	/** +886 912-345-678 這類寫法轉成 0912345678。 */
	public static function normalize_mobile( string $phone ): string {
		$digits = (string) preg_replace( '/\D+/', '', $phone );
		if ( str_starts_with( $digits, '886' ) ) {
			$digits = '0' . substr( $digits, 3 );
		}
		return $digits;
	}

	private static function is_newebpay_cvs( array $method_ids ): bool {
		foreach ( $method_ids as $id ) {
			if ( str_starts_with( (string) $id, 'moksafowo_newebpay_shipping_cvs' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function phone_message(): string {
		return __( 'For convenience store pickup, please enter a mobile number starting with 09 (10 digits) so the store can text you when the parcel arrives.', 'moksa-for-woocommerce' );
	}

	/**
	 * @param array<string,mixed> $data   結帳資料。
	 * @param \WP_Error           $errors 錯誤。
	 */
	public static function validate_classic_phone( $data, $errors ): void {
		if ( ! $errors instanceof \WP_Error || ! self::is_newebpay_cvs( (array) ( $data['shipping_method'] ?? [] ) ) ) {
			return;
		}
		$phone = self::normalize_mobile( (string) ( $data['shipping_phone'] ?? '' ) ?: (string) ( $data['billing_phone'] ?? '' ) );
		if ( ! preg_match( '/^09\d{8}$/', $phone ) ) {
			$errors->add( 'moksafowo_newebpay_phone', self::phone_message() );
		}
	}

	public static function validate_block_phone( \WC_Order $order ): void {
		$ids = [];
		foreach ( $order->get_shipping_methods() as $m ) {
			$ids[] = (string) $m->get_method_id();
		}
		if ( ! self::is_newebpay_cvs( $ids ) ) {
			return;
		}
		$phone = self::normalize_mobile( (string) $order->get_shipping_phone() ?: (string) $order->get_billing_phone() );
		if ( ! preg_match( '/^09\d{8}$/', $phone ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'moksafowo_newebpay_phone', esc_html( self::phone_message() ), 400 );
		}
	}

	public static function store_as_shipping_address( string $address, $raw_address, \WC_Order $order ): string {
		$store_id = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_STORE_ID );
		if ( '' === $store_id ) {
			return $address;
		}
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( ! str_starts_with( (string) $method->get_method_id(), 'moksafowo_newebpay_shipping_cvs' ) ) {
				return $address;
			}
			break;
		}
		return \Moksafowo\Modules\Shipping\Frontend\CvsShippingAddress::render(
			$order,
			$store_id,
			(string) $order->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_STORE_NAME ),
			(string) $order->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_SHIPPING_STORE_ADDR )
		);
	}
}
