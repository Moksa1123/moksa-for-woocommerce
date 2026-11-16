<?php
/**
 * 幕前建單：統一金流付款頁一起建物流單（PAYUNi 官方外掛的做法）。
 *
 * @package Moksafowo
 */

declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping;

use Moksafowo\Modules\PayuniShipping\Api\ShippingResponse;
use Moksafowo\Modules\PayuniShipping\Providers\SevenEleven\B2CUnified;
use Moksafowo\Modules\PayuniShipping\Providers\SevenEleven\C2CUnified;
use Moksafowo\Modules\PayuniShipping\Providers\TCat\HDUnified;
use Moksafowo\Modules\PayuniShipping\Utils\GoodsType;
use Moksafowo\Modules\PayuniShipping\Utils\LgsType;
use Moksafowo\Modules\PayuniShipping\Utils\MethodIdPredicates;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;
use Moksafowo\Modules\PayuniShipping\Utils\ShipType;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

/**
 * 幕前模式下：付款請求帶物流欄位、選統一物流時只留統一金流、結帳頁不選店（在付款頁選）、
 * 付款通知裡的物流單號與門市存回訂單，之後列印、查詢、貨態沿用原本的程式。
 */
final class Frontstage {

	/** 訂單是用付款頁一起建單（不再呼叫舊的建單 API）。 */
	public const ORDER_FLAG = '_moksafowo_payuni_shipping_upp';

	/** 這張訂單在付款頁選了取貨付款／宅配到付。 */
	public const PAY_ON_DELIVERY_FLAG = '_moksafowo_payuni_shipping_pay_on_delivery';

	/** 允許在付款頁選取貨付款。 */
	public const COD_OPTION = 'moksafowo_payuni_shipping_upp_cod';

	public static function init(): void {
		// 付款通知網址收到的物流通知、付款結果裡的物流資料：兩種模式都要收（模式切換前後的訂單都可能有）。
		add_action( 'moksafowo_payuni_payment_logistics_notify', array( __CLASS__, 'route_logistics_notify' ) );
		add_action( 'moksafowo_payuni_payment_result', array( __CLASS__, 'store_shipment_from_payment' ), 10, 2 );
		add_filter( 'moksafowo_invoice_order_is_pay_on_delivery', array( __CLASS__, 'is_pay_on_delivery' ), 10, 2 );

		if ( ! CreateMode::is_frontend() ) {
			return;
		}
		add_filter( 'moksafowo_payuni_transaction_args_data', array( __CLASS__, 'add_shipping_args' ), 20, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'only_payuni_payment' ), 50 );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_classic' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_block' ), 25, 2 );
	}

	/**
	 * 訂單上的統一物流運送方式 ID（沒有就空字串）。
	 */
	public static function order_method_id( \WC_Order $order ): string {
		foreach ( $order->get_shipping_methods() as $item ) {
			$mid = (string) $item->get_method_id();
			if ( MethodIdPredicates::is_payuni_shipping( $mid ) ) {
				return $mid;
			}
		}
		return '';
	}

	/**
	 * 溫層：不分溫層的方式依商品溫層決定；同一筆有兩種以上溫層時回傳空字串（付款頁一筆只能一種）。
	 *
	 * @param string $method_id 運送方式 ID。
	 * @param int[]  $temps     商品溫層（ProductTemp 常數）。
	 */
	public static function goods_type( string $method_id, array $temps ): string {
		if ( str_ends_with( $method_id, '_frozen' ) ) {
			return GoodsType::FROZEN;
		}
		if ( str_ends_with( $method_id, '_refrigerated' ) ) {
			return GoodsType::REFRIGERATED;
		}
		if ( str_ends_with( $method_id, '_normal' ) ) {
			return GoodsType::NORMAL;
		}
		$class = array(
			B2CUnified::ID => B2CUnified::class,
			C2CUnified::ID => C2CUnified::class,
			HDUnified::ID  => HDUnified::class,
		)[ $method_id ] ?? null;
		if ( null === $class ) {
			return GoodsType::NORMAL;
		}
		$types = array();
		foreach ( ( $temps ? $temps : array( ProductTemp::NORMAL ) ) as $temp ) {
			$types[ (string) $class::moksafowo_payuni_goods_type_for_temp( (int) $temp ) ] = true;
		}
		return 1 === count( $types ) ? (string) array_key_first( $types ) : '';
	}

	/**
	 * 付款請求加上物流欄位（moksafowo_payuni_transaction_args_data）。
	 *
	 * @param array<string,mixed> $args  付款請求。
	 * @param \WC_Order           $order 訂單。
	 * @return array<string,mixed>
	 */
	public static function add_shipping_args( $args, $order ) {
		if ( ! is_array( $args ) || ! $order instanceof \WC_Order ) {
			return $args;
		}
		$method_id = self::order_method_id( $order );
		if ( '' === $method_id ) {
			return $args;
		}
		$goods_type = self::goods_type( $method_id, array_keys( ProductTemp::group_order_items( $order ) ) );
		if ( '' === $goods_type ) {
			return $args; // 結帳已擋，這裡保險：不帶物流，付款照常。
		}

		$name  = trim( $order->get_shipping_last_name() . $order->get_shipping_first_name() );
		$name  = '' !== $name ? $name : trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		$phone = preg_replace( '/\D+/', '', (string) ( $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone() ) );

		$args['ShipTag']         = 1;
		$args['GoodsType']       = $goods_type;
		$args['Consignee']       = $name;
		$args['ConsigneeMobile'] = $phone;
		// 姓名、手機符合 PAYUNi 規則才鎖住；不符合就讓顧客在付款頁改，不然整筆會被退。
		if ( preg_match( '/^(\p{Han}{2,5}|[A-Za-z ]{4,})$/u', $name ) ) {
			$args['ConsigneeFix'] = 1;
		}
		if ( preg_match( '/^09\d{8}$/', (string) $phone ) ) {
			$args['ConsigneeMobileFix'] = 1;
		}

		if ( MethodIdPredicates::needs_cvs( $method_id ) ) {
			$args['ShipType'] = ShipType::SEVEN;
			$args['LgsType']  = false !== strpos( $method_id, '_b2c' ) ? LgsType::B2C : LgsType::C2C;
		} else {
			$args['ShipType']            = ShipType::TCAT;
			$args['LgsType']             = LgsType::HOME;
			$args['ConsigneeAddress']    = trim( $order->get_shipping_state() . $order->get_shipping_city() . $order->get_shipping_address_1() . $order->get_shipping_address_2() );
			$args['ConsigneeAddressFix'] = 1;
		}

		// 取貨付款只在「統一金流（單一入口）」付款頁提供，顧客在付款頁自己選；其他統一金流方式是指定好的付款工具。
		if ( 'yes' === get_option( self::COD_OPTION, 'no' ) && \Moksafowo\Modules\Payuni\Gateways\Unified::GATEWAY_ID === $order->get_payment_method() ) {
			$args['Ship'] = 1;
		}

		$order->update_meta_data( OrderMeta::LgsType, $args['LgsType'] );
		$order->update_meta_data( OrderMeta::GoodsType, $goods_type );
		$order->update_meta_data( OrderMeta::ShipType, (string) $args['ShipType'] );
		$order->save();
		return $args;
	}

	/**
	 * 選了統一物流時只留統一金流（物流單只能跟著統一金流的付款一起建）。
	 *
	 * @param array<string,\WC_Payment_Gateway> $gateways 可用付款方式。
	 * @return array<string,\WC_Payment_Gateway>
	 */
	public static function only_payuni_payment( $gateways ) {
		if ( ! is_array( $gateways ) || ( is_admin() && ! wp_doing_ajax() ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return $gateways;
		}
		if ( ! self::chosen_is_payuni_shipping() && ! self::order_pay_needs_payuni() ) {
			return $gateways;
		}
		return array_filter(
			$gateways,
			static fn ( $id ): bool => 0 === strpos( (string) $id, 'moksafowo_payuni_' ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/** 「訂單付款」頁（店家建單後給顧客付款）：訂單用統一物流時，一樣只能用統一金流。 */
	private static function order_pay_needs_payuni(): bool {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return false;
		}
		$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
		return $order instanceof \WC_Order && '' !== self::order_method_id( $order );
	}

	/** 工作階段裡選的統一物流運送方式 ID（沒有就空字串）。 */
	private static function chosen_method_id(): string {
		foreach ( (array) WC()->session->get( 'chosen_shipping_methods', array() ) as $chosen ) {
			$mid = strstr( (string) $chosen, ':', true );
			$mid = false === $mid ? (string) $chosen : $mid;
			if ( MethodIdPredicates::is_payuni_shipping( $mid ) ) {
				return $mid;
			}
		}
		return '';
	}

	private static function chosen_is_payuni_shipping(): bool {
		foreach ( (array) WC()->session->get( 'chosen_shipping_methods', array() ) as $chosen ) {
			$mid = strstr( (string) $chosen, ':', true );
			if ( MethodIdPredicates::is_payuni_shipping( false === $mid ? (string) $chosen : $mid ) ) {
				return true;
			}
		}
		return false;
	}

	/** 結帳時：統一物流要搭統一金流，且購物車只能有一種溫層。 */
	private static function checkout_error( string $payment_method, string $method_id ): string {
		if ( '' === $method_id ) {
			return '';
		}
		if ( 0 !== strpos( $payment_method, 'moksafowo_payuni_' ) ) {
			return __( 'PAYUNi shipping is booked together with the PAYUNi payment, so please choose a PAYUNi payment method.', 'moksa-for-woocommerce' );
		}
		if ( '' === self::goods_type( $method_id, ProductTemp::temps_in_cart() ) ) {
			return __( 'PAYUNi can only ship one temperature type per payment. Please place separate orders for ambient and chilled or frozen items.', 'moksa-for-woocommerce' );
		}
		return '';
	}

	public static function validate_classic(): void {
		if ( ! isset( $_POST['woocommerce-process-checkout-nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['woocommerce-process-checkout-nonce'] ) ), 'woocommerce-process_checkout' ) ) {
			return;
		}
		$method_id = '';
		foreach ( isset( $_POST['shipping_method'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['shipping_method'] ) ) : array() as $chosen ) {
			$mid = strstr( $chosen, ':', true );
			$mid = false === $mid ? $chosen : $mid;
			if ( MethodIdPredicates::is_payuni_shipping( $mid ) ) {
				$method_id = $mid;
			}
		}
		if ( '' === $method_id && function_exists( 'WC' ) && WC()->session && self::chosen_is_payuni_shipping() ) {
			$method_id = self::chosen_method_id();
		}
		$payment = isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : '';
		$error   = self::checkout_error( $payment, $method_id );
		if ( '' !== $error ) {
			wc_add_notice( $error, 'error' );
		}
	}

	/**
	 * @param \WC_Order $order   訂單。
	 * @param mixed     $request Store API 請求。
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException 不能用統一物流時.
	 */
	public static function validate_block( $order, $request = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( $request && method_exists( $request, 'get_param' ) && $request->get_param( '__experimental_calc_totals' ) ) {
			return;
		}
		$payment = (string) $order->get_payment_method();
		if ( '' === $payment && $request && method_exists( $request, 'get_param' ) ) {
			$payment = (string) $request->get_param( 'payment_method' );
		}
		$error = self::checkout_error( $payment, self::order_method_id( $order ) );
		if ( '' !== $error ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'moksafowo_payuni_upp_shipping', esc_html( $error ), 400 );
		}
	}

	/**
	 * 付款結果帶回的物流資料存進訂單；取貨付款的訂單轉成待出貨。
	 *
	 * @param \WC_Order           $order 訂單。
	 * @param array<string,mixed> $info  解密後的付款通知。
	 */
	public static function store_shipment_from_payment( $order, $info ): void {
		if ( ! $order instanceof \WC_Order || ! is_array( $info ) || 'SUCCESS' !== (string) ( $info['Status'] ?? '' ) ) {
			return;
		}
		if ( '' === (string) ( $info['ShipTradeNo'] ?? '' ) ) {
			self::note_missing_shipment( $order, $info );
			return;
		}
		// 同一個物流序號只處理一次：伺服器通知與瀏覽器返回會各來一次，顧客也可能重送自己的返回資料。
		if ( (string) $order->get_meta( OrderMeta::ShipTradeNo ) === (string) $info['ShipTradeNo'] ) {
			return;
		}
		$order->update_meta_data( self::ORDER_FLAG, 'yes' );
		$order->update_meta_data( OrderMeta::ShipTradeNo, (string) $info['ShipTradeNo'] );
		foreach ( array(
			OrderMeta::LgsType     => 'LgsType',
			OrderMeta::GoodsType   => 'GoodsType',
			OrderMeta::ShipType    => 'ShipType',
			OrderMeta::ServiceType => 'ServiceType',
			OrderMeta::TradeAmt    => 'ShipAmt',
			OrderMeta::PartnerId   => 'PartnerId',
		) as $meta => $field ) {
			if ( '' !== (string) ( $info[ $field ] ?? '' ) ) {
				$order->update_meta_data( $meta, (string) $info[ $field ] );
			}
		}
		if ( '' !== (string) ( $info['StoreID'] ?? '' ) ) {
			$store = array(
				'id'      => (string) $info['StoreID'],
				'name'    => (string) ( $info['StoreName'] ?? '' ),
				'address' => (string) ( $info['StoreAddr'] ?? '' ),
			);
			$order->update_meta_data( OrderMeta::StoreId, $store['id'] );
			$order->update_meta_data( OrderMeta::StoreName, $store['name'] );
			$order->update_meta_data( OrderMeta::StoreAddr, $store['address'] );
			$order->update_meta_data( OrderMeta::STORE_DATA_JSON, wp_json_encode( $store ) );
			$order->update_meta_data( \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_ID, $store['id'] );
			$order->update_meta_data( \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_NAME, $store['name'] );
			$order->update_meta_data( \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_ADDRESS, $store['address'] );
		}
		$pay_on_delivery = in_array( (int) ( $info['PaymentType'] ?? 0 ), array( 5, 10 ), true ) && '0' === (string) ( $info['TradeStatus'] ?? '' );
		if ( $pay_on_delivery ) {
			$order->update_meta_data( self::PAY_ON_DELIVERY_FLAG, 'yes' );
		}
		$order->save();

		$order->add_order_note(
			\Moksafowo\Order\Note::build(
				__( 'PAYUNi created the shipment with the payment.', 'moksa-for-woocommerce' ),
				array(
					__( 'Shipping transaction ID', 'moksa-for-woocommerce' ) => (string) $info['ShipTradeNo'],
					__( 'Pickup store', 'moksa-for-woocommerce' )            => trim( (string) ( $info['StoreName'] ?? '' ) . ' ' . (string) ( $info['StoreID'] ?? '' ) ),
				)
			)
		);

		if ( $pay_on_delivery && $order->has_status( 'pending' ) ) {
			// 取貨時門市照這個金額收款：跟訂單總額不符（例如送出後改了訂單金額）就不放行出貨，留給店家確認。
			$amount = (int) ( $info['TradeAmt'] ?? 0 );
			$total  = (float) $order->get_total();
			if ( $amount !== (int) round( $total ) && $amount !== (int) ceil( $total ) ) {
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: 1: amount collected on pickup, 2: order total */
						__( 'PAYUNi will collect %1$s on pickup, which does not match the order total of %2$s. Please check before shipping.', 'moksa-for-woocommerce' ),
						$amount,
						$order->get_total()
					)
				);
				return;
			}
			$order->update_status( 'processing', __( 'The customer will pay on pickup. The order is ready to ship.', 'moksa-for-woocommerce' ) );
		}
	}

	/**
	 * 幕前模式下付款成功、卻沒帶回物流序號（例如付款頁沒帶到物流、或改用其他方式付款）：留備註提醒店家。
	 *
	 * @param \WC_Order           $order 訂單。
	 * @param array<string,mixed> $info  解密後的付款通知。
	 */
	private static function note_missing_shipment( \WC_Order $order, array $info ): void {
		if ( ! CreateMode::is_frontend() || '1' !== (string) ( $info['TradeStatus'] ?? '' ) || '' === self::order_method_id( $order ) ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( OrderMeta::ShipTradeNo ) || 'yes' === $order->get_meta( '_moksafowo_payuni_shipping_missing_noted' ) ) {
			return;
		}
		$order->update_meta_data( '_moksafowo_payuni_shipping_missing_noted', 'yes' );
		$order->save();
		$order->add_order_note( __( 'The payment went through, but PAYUNi did not return a shipment for this order. Please create the shipment in the PAYUNi merchant dashboard.', 'moksa-for-woocommerce' ) );
	}

	/**
	 * 發票：付款頁選了取貨付款的訂單，跟貨到付款一樣等完成才開。
	 *
	 * @param bool      $is    目前判斷。
	 * @param \WC_Order $order 訂單。
	 */
	public static function is_pay_on_delivery( $is, $order ): bool {
		return (bool) $is || ( $order instanceof \WC_Order && 'yes' === $order->get_meta( self::PAY_ON_DELIVERY_FLAG ) );
	}

	/**
	 * 付款通知網址收到的物流通知，依物流方式轉給 7-11 或黑貓的貨態處理。
	 *
	 * @param array<string,mixed> $info 解密後的通知。
	 */
	public static function route_logistics_notify( $info ): void {
		if ( ! is_array( $info ) ) {
			return;
		}
		if ( LgsType::HOME === (string) ( $info['LgsType'] ?? '' ) || isset( $info['OBTNumber'] ) || isset( $info['JsonData'] ) ) {
			ShippingResponse::handle_tcat( $info );
			return;
		}
		ShippingResponse::handle_711( $info );
	}
}
