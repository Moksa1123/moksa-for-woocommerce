<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 唯讀：向金流商實際打 API 問這筆交易的現況。
 *
 * 跟 get-payment-status 的差別 —— 那支只讀訂單上的 meta（我們自己記的），
 * 對不上帳時沒用；這支是真的去問金流商，用在「顧客說付了但系統沒收到」、
 * 「請款到底成功沒」這種情境。
 *
 * 輸出只給白名單欄位，並且再過一次卡號類關鍵字 —— 金流商回應可能帶卡片資訊，
 * 那不該進到 AI 對話（也不該進 log，見 Logging\Redactor）。
 */
final class QueryPayment {

	const CAP = 'manage_woocommerce';

	/** 允許輸出的欄位（其餘一律丟掉，寧可少給也不要外洩） */
	private const ALLOWED = [
		// 交易不存在時綠界只回這兩個 —— 一定要留，否則 AI 拿到空物件會不知道發生什麼事
		'RtnCode',
		'RtnMsg',
		'Status',
		'Amount',
		'AuthTime',
		'CloseData',
		'ClsAmt',
		'TradeNo',
		'TradeStatus',
		'TradeAmt',
		'MerTradeNo',
		'PaymentType',
		'PaymentDay',
		'CreateDay',
		'Message',
		'CloseStatus',
		'datetime',
		'status',
	];

	/**
	 * @param mixed $args { order: string|int }.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function run( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}

		$ref   = is_array( $args ) && isset( $args['order'] ) ? (string) $args['order'] : '';
		$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			/* translators: %s: order reference the user gave */
			return new \WP_Error( 'moksafowo_ai_no_order', sprintf( __( 'Order not found: %s.', 'moksa-for-woocommerce' ), $ref ) );
		}

		$gateway = (string) $order->get_payment_method();

		if ( 'moksafowo_ecpay_credit' === $gateway ) {
			return self::from_ecpay( $order, $gateway );
		}
		if ( str_starts_with( $gateway, 'moksafowo_payuni_' ) ) {
			return self::from_payuni( $order, $gateway );
		}

		return new \WP_Error(
			'moksafowo_ai_gateway_unsupported',
			sprintf(
				/* translators: 1: order number, 2: payment method title */
				__( 'Order #%1$s was paid with %2$s, which cannot be looked up at the provider from here. Only ECPay card payments and PAYUNi support this. Use get-payment-status for what this site already recorded.', 'moksa-for-woocommerce' ),
				$order->get_order_number(),
				$order->get_payment_method_title() ?: $gateway
			)
		);
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function from_ecpay( \WC_Order $order, string $gateway ) {
		if ( ! class_exists( \Moksafowo\Modules\Ecpay\Api\Helper::class ) ) {
			return new \WP_Error( 'moksafowo_ai_module_off', __( 'The ECPay payment module is switched off, so the provider cannot be contacted.', 'moksa-for-woocommerce' ) );
		}

		$result = \Moksafowo\Modules\Ecpay\Api\Helper::query_credit_trade( $order );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$value = is_array( $result ) ? ( $result['RtnValue'] ?? $result ) : [];

		return [
			'order_id' => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'gateway'  => $gateway,
			'provider' => 'ECPay',
			'details'  => self::clean( is_array( $value ) ? $value : [] ),
		];
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function from_payuni( \WC_Order $order, string $gateway ) {
		if ( ! class_exists( \Moksafowo\Modules\Payuni\Api\PaymentRequest::class ) ) {
			return new \WP_Error( 'moksafowo_ai_module_off', __( 'The PAYUNi payment module is switched off, so the provider cannot be contacted.', 'moksa-for-woocommerce' ) );
		}

		// add_note = false：這是唯讀查詢，不該在訂單留下一堆備註
		$result = \Moksafowo\Modules\Payuni\Api\PaymentRequest::query( $order->get_id(), false );
		if ( false === $result || ! is_array( $result ) ) {
			return new \WP_Error( 'moksafowo_ai_query_failed', __( 'PAYUNi did not return any information for this order.', 'moksa-for-woocommerce' ) );
		}

		return [
			'order_id' => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'gateway'  => $gateway,
			'provider' => 'PAYUNi',
			'details'  => self::clean( $result ),
		];
	}

	/**
	 * 白名單過濾 + 卡片關鍵字二次防守。
	 *
	 * @param array<string,mixed> $data Raw provider response.
	 * @return array<string,mixed>
	 */
	private static function clean( array $data ): array {
		$out = [];
		foreach ( $data as $key => $value ) {
			$key = (string) $key;
			if ( preg_match( '/card|pan|cvv|cvc|expiry|holder|token/i', $key ) ) {
				continue;
			}
			if ( ! in_array( $key, self::ALLOWED, true ) ) {
				continue;
			}
			$out[ $key ] = is_array( $value ) ? array_map(
				static fn( $row ) => is_array( $row ) ? self::clean( $row ) : $row,
				$value
			) : $value;
		}
		return $out;
	}
}
