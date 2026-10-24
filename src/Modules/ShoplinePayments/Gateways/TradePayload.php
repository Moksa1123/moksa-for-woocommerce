<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments\Gateways;

use Moksafowo\Modules\ShoplinePayments\Api\Client;
use Moksafowo\Modules\ShoplinePayments\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 導轉式（session）與內嵌式（SDK）建立交易共用的訂單、顧客資料組裝與退款。
 */
trait TradePayload {

	protected function slp_refund( $order_id, $amount, string $reason ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'moksafowo_slp_invalid_order', __( 'The order does not exist.', 'moksa-for-woocommerce' ) );
		}

		$trade_order_id = (string) $order->get_meta( Keys::SLP_TRADE_ORDER_ID );
		if ( '' === $trade_order_id ) {
			return new \WP_Error( 'moksafowo_slp_missing_trade_order_id', __( 'The order has no Shopline Payments transaction ID (tradeOrderId).', 'moksa-for-woocommerce' ) );
		}

		$value = (int) round( (float) $amount * 100 );
		if ( $value <= 0 ) {
			return new \WP_Error( 'moksafowo_slp_invalid_amount', __( 'The refund amount must be greater than 0.', 'moksa-for-woocommerce' ) );
		}

		// 退款單號規格要求特店端唯一。WC 呼叫這裡之前已建好這筆退款紀錄，用它的 ID：
		// 每筆退款不同，同一筆網路重試則相同（冪等）。開頭保留訂單號，Webhook 靠它反查訂單。
		$refunds            = $order->get_refunds();
		$refund_id          = ! empty( $refunds ) ? (string) $refunds[0]->get_id() : (string) time();
		$reference_order_id = $order_id . '-R' . $refund_id;
		$idempotent_key     = Helper::idempotent_key( sprintf( 'refund-%d-%s', $order_id, $refund_id ) );

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
					__( 'The Shopline Payments refund failed: %s', 'moksa-for-woocommerce' ),
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
				__( 'The Shopline Payments refund of NT$%1$s was submitted (refund number %2$s) — %3$s', 'moksa-for-woocommerce' ),
				number_format( $value / 100, 0 ),
				'' !== $refund_order_id ? $refund_order_id : __( 'Processing', 'moksa-for-woocommerce' ),
				'' !== $reason ? $reason : __( 'No reason given', 'moksa-for-woocommerce' )
			)
		);
		$order->save();
		return true;
	}

	protected function build_order( \WC_Order $order ): array {
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

	protected function build_products( \WC_Order $order ): array {
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
				'name'     => sprintf( __( '%s order', 'moksa-for-woocommerce' ), get_bloginfo( 'name' ) ),
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

	protected function build_billing( \WC_Order $order ): array {
		return [
			'personalInfo' => $this->build_personal_info( $order ),
			'address'      => $this->build_address( $order ),
		];
	}

	protected function build_customer( \WC_Order $order ): array {
		return $this->build_customer_base( $order );
	}

	protected function build_customer_base( \WC_Order $order ): array {
		$customer_id = (int) $order->get_customer_id();
		return [
			'referenceCustomerId' => $customer_id > 0 ? (string) $customer_id : 'guest-' . $order->get_id(),
			'type'                => $customer_id > 0 ? '1' : '0',
			'personalInfo'        => $this->build_personal_info( $order ),
		];
	}

	protected function build_personal_info( \WC_Order $order ): array {
		return [
			'firstName' => (string) $order->get_billing_first_name(),
			'lastName'  => (string) $order->get_billing_last_name(),
			'email'     => (string) $order->get_billing_email(),
			'phone'     => self::to_e164( (string) $order->get_billing_phone() ),
		];
	}

	protected function build_address( \WC_Order $order ): array {
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

	protected function build_client(): array {
		return [
			'ip'        => self::client_ip(),
			'userAgent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : 'Mozilla/5.0',
			'accept'    => isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] ) ) : 'text/html',
			'language'  => 'zh-TW',
		];
	}

	protected static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '163.61.60.30';
	}

	protected static function to_e164( string $phone ): string {
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
