<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * 取貨付款有金額範圍（門市／司機實收），超出範圍物流商會拒絕建單，訂單卻已成立。
 * 結帳時金額不在所選物流的範圍內，就不提供「貨到付款」；只拿掉 cod 這一個付款方式。
 *
 * 範圍依各家規格：綠界超商 1–20,000、黑貓代收 30–20,000；藍新超商 20,000（2103）；
 * 速買配 C2C 13–19,999；PAYUNi 30–20,000（SHIP02025／HOME01035）。
 * 商家可用 moksafowo_cod_amount_limits 調整。
 */
final class CodAmountLimit {

	public static function init(): void {
		add_filter( 'woocommerce_available_payment_gateways', [ __CLASS__, 'filter' ], 20 );
	}

	/** @return array<string,array{0:int,1:int}> 運送方式 ID 前綴 => [下限, 上限] */
	private static function limits(): array {
		return (array) apply_filters(
			'moksafowo_cod_amount_limits',
			[
				'moksafowo_ecpay_shipping_cvs_'      => [ 1, 20000 ],
				'moksafowo_ecpay_shipping_home_tcat' => [ 30, 20000 ],
				'moksafowo_newebpay_shipping_cvs'    => [ 1, 20000 ],
				'moksafowo_smilepay_shipping_cvs_'   => [ 13, 19999 ],
				'moksafowo_payuni_shipping_'         => [ 30, 20000 ],
			]
		);
	}

	/**
	 * @param array<string,\WC_Payment_Gateway> $gateways 可用付款方式。
	 * @return array<string,\WC_Payment_Gateway>
	 */
	public static function filter( $gateways ) {
		if ( ! is_array( $gateways ) || ! isset( $gateways['cod'] ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $gateways;
		}
		// 付款頁（失敗後重付）用那張訂單的運送方式與金額，購物車是空的。
		$pay_order = is_wc_endpoint_url( 'order-pay' ) ? wc_get_order( absint( get_query_var( 'order-pay' ) ) ) : null;
		if ( $pay_order instanceof \WC_Order ) {
			$method = '';
			foreach ( $pay_order->get_shipping_methods() as $item ) {
				$method = (string) $item->get_method_id();
				break;
			}
			$total = (float) $pay_order->get_total();
		} else {
			if ( ! WC()->session || ! WC()->cart ) {
				return $gateways;
			}
			$chosen = (array) WC()->session->get( 'chosen_shipping_methods', [] );
			$method = (string) ( $chosen[0] ?? '' );
			$total  = (float) WC()->cart->get_total( 'edit' );
		}
		if ( '' === $method ) {
			return $gateways;
		}
		foreach ( self::limits() as $prefix => $range ) {
			if ( str_starts_with( $method, (string) $prefix ) ) {
				if ( $total < (float) $range[0] || $total > (float) $range[1] ) {
					unset( $gateways['cod'] );
				}
				break;
			}
		}
		return $gateways;
	}
}
