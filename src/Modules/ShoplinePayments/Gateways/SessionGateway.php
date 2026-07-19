<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments\Gateways;

use Moksafowo\Modules\ShoplinePayments\Api\Client;
use Moksafowo\Modules\ShoplinePayments\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class SessionGateway extends \WC_Payment_Gateway {

	public const GATEWAY_ID = 'moksafowo_shopline_payments';

	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'Shopline Payments', 'moksa-for-woocommerce' );
		$this->method_description = __( '跳轉至 Shopline Payments 託管結帳頁，支援信用卡 / Apple Pay / Google Pay / LINE Pay / 街口等。', 'moksa-for-woocommerce' );
		$this->supports           = [ 'products', 'refunds' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title', $this->method_title );
		$this->description = (string) $this->get_option( 'description', '' );
		$this->enabled     = (string) $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'     => [
				'title'   => __( '啟用此付款方式', 'moksa-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( '前台顯示名稱', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'default'     => $this->method_title,
				'description' => __( '結帳頁顯示給顧客看的名稱。', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( '前台顯示描述', 'moksa-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( '結帳頁付款方式描述。', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
		];
	}

	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		return Helper::has_credentials();
	}


	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( esc_html__( '找不到訂單', 'moksa-for-woocommerce' ) );
		}

		// 曾送過（失敗重試）→ referenceId 加時間後綴避免 SLP 端撞號。
		$retry        = '' !== (string) $order->get_meta( Keys::SLP_REFERENCE_ID );
		$reference_id = Helper::build_reference_id( (int) $order_id, $retry );

		// allowPaymentMethodList 為 SLP 必填 — 留空時 fallback 信用卡。
		$allowed = Helper::allowed_payment_methods();
		if ( $allowed === [] ) {
			$allowed = [ 'CreditCard' ];
		}

		$body = [
			'referenceId'            => $reference_id,
			'amount'                 => [
				// ×100：先 round WC total（避免浮點尾差）再轉整數 cents。TWD 仍 ×100。
				'value'    => (int) round( (float) $order->get_total() * 100 ),
				'currency' => 'TWD',
			],
			'returnUrl'              => $order->get_checkout_order_received_url(),
			'mode'                   => 'regular',
			'allowPaymentMethodList' => $allowed,
			'order'                  => $this->build_order( $order ),
			'billing'                => $this->build_billing( $order ),
			'customer'               => $this->build_customer( $order ),
			'client'                 => $this->build_client(),
		];

		$resp        = Client::create_session( $body );
		$data        = $resp['data'];
		$session_id  = (string) ( $data['sessionId'] ?? '' );
		$session_url = (string) ( $data['sessionUrl'] ?? '' );

		Helper::log(
			'session create',
			[
				'order_id'     => $order_id,
				'reference_id' => $reference_id,
				'ok'           => $resp['ok'],
				'code'         => $resp['code'],
				'session_id'   => $session_id,
			]
		);

		if ( ! $resp['ok'] || '' === $session_url ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: error message */
					__( '無法建立 Shopline Payments 付款：%s', 'moksa-for-woocommerce' ),
					$resp['message']
				),
				'error'
			);
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}

		$order->update_meta_data( Keys::SLP_REFERENCE_ID, $reference_id );
		$order->update_meta_data( Keys::SLP_SESSION_ID, $session_id );
		$order->update_meta_data( Keys::SLP_SESSION_URL, $session_url );
		$order->update_meta_data( Keys::SLP_STATUS, (string) ( $data['status'] ?? 'CREATED' ) );
		$order->update_status( 'pending', __( '等待顧客於 Shopline Payments 完成付款。', 'moksa-for-woocommerce' ) );
		$order->save();

		return [
			'result'   => 'success',
			'redirect' => $session_url,
		];
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'moksafowo_slp_invalid_order', __( '訂單不存在。', 'moksa-for-woocommerce' ) );
		}

		$trade_order_id = (string) $order->get_meta( Keys::SLP_TRADE_ORDER_ID );
		if ( '' === $trade_order_id ) {
			return new \WP_Error( 'moksafowo_slp_missing_trade_order_id', __( '訂單缺少 Shopline Payments 交易編號（tradeOrderId）。', 'moksa-for-woocommerce' ) );
		}

		$reference_order_id = (string) $order->get_meta( Keys::SLP_REFERENCE_ID );
		if ( '' === $reference_order_id ) {
			$reference_order_id = Helper::build_reference_id( (int) $order_id );
		}

		$value = (int) round( (float) $amount * 100 );
		if ( $value <= 0 ) {
			return new \WP_Error( 'moksafowo_slp_invalid_amount', __( '退款金額必須大於 0。', 'moksa-for-woocommerce' ) );
		}

		// WC refund id（this request 的退款記錄）— 無法直接取得，用 time 後綴穩定識別。
		$refund_id      = (string) ( $order->get_meta( Keys::SLP_REFUND_ORDER_ID ) ?: time() );
		$idempotent_key = Helper::idempotent_key( sprintf( 'refund-%d-%s', $order_id, $refund_id ) );

		$payload = [
			'referenceOrderId' => $reference_order_id,
			'tradeOrderId'     => $trade_order_id,
			'amount'           => [
				'value'    => $value,
				'currency' => 'TWD',
			],
		];
		if ( '' !== $reason ) {
			$payload['reason'] = $reason;
		}

		$resp = Client::create_refund( $payload, $idempotent_key );

		Helper::log(
			'refund create',
			[
				'order_id'       => $order_id,
				'trade_order_id' => $trade_order_id,
				'value'          => $value,
				'ok'             => $resp['ok'],
				'code'           => $resp['code'],
			]
		);

		if ( ! $resp['ok'] ) {
			return new \WP_Error(
				'moksafowo_slp_refund_fail',
				sprintf(
					/* translators: %s: error message */
					__( 'Shopline Payments 退款失敗：%s', 'moksa-for-woocommerce' ),
					$resp['message']
				)
			);
		}

		$refund_order_id = (string) ( $resp['data']['refundOrderId'] ?? '' );
		if ( '' !== $refund_order_id ) {
			$order->update_meta_data( Keys::SLP_REFUND_ORDER_ID, $refund_order_id );
		}
		$order->add_order_note(
			sprintf(
			/* translators: 1: amount, 2: refund order id, 3: reason */
				__( 'Shopline Payments 退款已送出（NT$%1$s，退款編號 %2$s）— %3$s', 'moksa-for-woocommerce' ),
				number_format( $value / 100, 0 ),
				'' !== $refund_order_id ? $refund_order_id : __( '處理中', 'moksa-for-woocommerce' ),
				'' !== $reason ? $reason : __( '無原因', 'moksa-for-woocommerce' )
			)
		);
		$order->save();
		return true;
	}

	private function build_order( \WC_Order $order ): array {
		$shipping_method = (string) ( $order->get_shipping_method() ?: 'N/A' );
		return [
			'products' => $this->build_products( $order ),
			'shipping' => [
				'shippingMethod' => mb_substr( $shipping_method, 0, 64 ),
				'carrier'        => mb_substr( $shipping_method, 0, 64 ),
				'personalInfo'   => $this->build_personal_info( $order ),
				'address'        => $this->build_address( $order ),
			],
		];
	}

	private function build_products( \WC_Order $order ): array {
		$products = [];
		foreach ( $order->get_items() as $item ) {
			$qty        = max( 1, (int) $item->get_quantity() );
			$product    = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			$product_id = $item instanceof \WC_Order_Item_Product ? (int) $item->get_product_id() : 0;
			$products[] = [
				'id'       => (string) ( $product_id > 0 ? $product_id : $item->get_id() ),
				'name'     => mb_substr( (string) $item->get_name(), 0, 100 ),
				'quantity' => $qty,
				'sku'      => $product instanceof \WC_Product ? (string) $product->get_sku() : '',
				'amount'   => [
					// SLP 智慧風控必填 — 商品行總額 ×100（非單價）。
					'value'    => (int) round( (float) $item->get_total() * 100 ),
					'currency' => 'TWD',
				],
			];
		}
		if ( $products === [] ) {
			$products[] = [
				'id'       => (string) $order->get_id(),
				/* translators: %s: site name */
				'name'     => sprintf( __( '%s 訂單', 'moksa-for-woocommerce' ), get_bloginfo( 'name' ) ),
				'quantity' => 1,
				'sku'      => '',
				'amount'   => [
					'value'    => (int) round( (float) $order->get_total() * 100 ),
					'currency' => 'TWD',
				],
			];
		}
		return $products;
	}

	private function build_billing( \WC_Order $order ): array {
		return [
			'personalInfo' => $this->build_personal_info( $order ),
			'address'      => $this->build_address( $order ),
		];
	}

	private function build_customer( \WC_Order $order ): array {
		$customer_id = (int) $order->get_customer_id();
		return [
			'referenceCustomerId' => $customer_id > 0 ? (string) $customer_id : 'guest-' . $order->get_id(),
			'type'                => $customer_id > 0 ? '1' : '0',
			'personalInfo'        => $this->build_personal_info( $order ),
		];
	}

	private function build_personal_info( \WC_Order $order ): array {
		return [
			'firstName' => (string) $order->get_billing_first_name(),
			'lastName'  => (string) $order->get_billing_last_name(),
			'email'     => (string) $order->get_billing_email(),
			'phone'     => self::to_e164( (string) $order->get_billing_phone() ),
		];
	}

	private function build_address( \WC_Order $order ): array {
		$street = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
		return [
			'countryCode' => (string) ( $order->get_billing_country() ?: 'TW' ),
			'state'       => (string) $order->get_billing_state(),
			'city'        => (string) $order->get_billing_state(),
			'district'    => (string) $order->get_billing_city(),
			'street'      => $street,
			'postcode'    => (string) $order->get_billing_postcode(),
		];
	}

	private function build_client(): array {
		return [
			'ip'        => self::client_ip(),
			'userAgent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : 'Mozilla/5.0',
			'accept'    => isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] ) ) : 'text/html',
			'language'  => 'zh-TW',
		];
	}

	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '163.61.60.30';
	}

	private static function to_e164( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';
		if ( '' === $digits ) {
			return '';
		}
		if ( str_starts_with( $digits, '886' ) ) {
			return '+' . $digits;
		}
		if ( str_starts_with( $digits, '0' ) ) {
			return '+886' . substr( $digits, 1 );
		}
		return '+886' . $digits;
	}
}
