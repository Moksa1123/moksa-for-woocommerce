<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Admin;

use Moksafowo\Modules\EcpayShipping\Operations\CreateOrder as EcpayCreate;
use Moksafowo\Modules\NewebpayShipping\Operations\CreateShipment as NewebpayCreate;
use Moksafowo\Modules\Shared\Admin\LastError;
use Moksafowo\Modules\SmilepayShipping\Operations\CreateOrder as SmilepayCreate;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 依訂單的運送方式找到對應物流商，向它建單取號。訂單列表按鈕與 AI 助手共用。
 */
final class ShipmentCreator {

	/**
	 * @return array<string, array{0: class-string|null, 1: string}> provider => [ 建單類別, 顯示名 ]
	 */
	public static function providers(): array {
		return [
			'ecpay'    => [ EcpayCreate::class, __( 'ECPay', 'moksa-for-woocommerce' ) ],
			'payuni'   => [ null, __( 'PAYUNi', 'moksa-for-woocommerce' ) ],
			'newebpay' => [ NewebpayCreate::class, __( 'NewebPay', 'moksa-for-woocommerce' ) ],
			'smilepay' => [ SmilepayCreate::class, __( 'SmilePay', 'moksa-for-woocommerce' ) ],
		];
	}

	/** 訂單運送方式所屬、而且模組有啟用的物流商；沒有就回空字串。 */
	public static function provider_for( \WC_Order $order ): string {
		foreach ( $order->get_shipping_methods() as $m ) {
			if ( preg_match( '/^moksafowo_(ecpay|payuni|newebpay|smilepay)_shipping_/', (string) $m->get_method_id(), $mm )
				&& 'yes' === get_option( 'moksafowo_' . $mm[1] . '_shipping_enabled', 'no' ) ) {
				return $mm[1];
			}
		}
		return '';
	}

	/** 已經向物流商建過單的識別值（不一定是可查件的物流編號，PAYUNi 會先只有交易序號）。 */
	public static function shipment_ref( \WC_Order $order ): string {
		$records = $order->get_meta( Keys::ECPAY_LOGISTIC_RECORDS );
		if ( is_array( $records ) && ! empty( $records ) ) {
			return (string) ( end( $records )['id'] ?? 'ecpay' );
		}
		$keys = [
			Keys::ECPAY_LOGISTIC_ID,
			Keys::ECPAY_LOGISTIC_CVS_PAYMENT_NO,
			Keys::NEWEBPAY_SHIPPING_LGS_NO,
			Keys::PAYUNI_SHIPPING_TRADE_NO,
			Keys::PAYUNI_SHIPPING_SNO,
			Keys::SMILEPAY_SHIPPING_NO,
			Keys::SMILEPAY_SHIPPING_PAY_NO,
		];
		foreach ( $keys as $key ) {
			$value = trim( (string) $order->get_meta( $key ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * @return array{ok: bool, message?: string}
	 */
	public static function create( \WC_Order $order ): array {
		$provider = self::provider_for( $order );
		if ( '' === $provider ) {
			return [
				'ok'      => false,
				'message' => __( 'The shipping method on this order does not support creating a shipment automatically.', 'moksa-for-woocommerce' ),
			];
		}
		return LastError::record( $order, Keys::SHIPPING_LAST_ERROR, $provider, self::run( $provider, $order ) );
	}

	/**
	 * PAYUNi 的完整入口不回傳結果，以建單後有沒有新出現物流單號判斷成敗；其他家走各自的 run()。
	 *
	 * @return array{ok: bool, message?: string}
	 */
	private static function run( string $provider, \WC_Order $order ): array {
		if ( 'payuni' === $provider ) {
			$class = 'Moksafowo\\Modules\\PayuniShipping\\Api\\ShippingRequest';
			if ( ! class_exists( $class ) ) {
				return [
					'ok'      => false,
					'message' => __( 'The PAYUNi shipping module is not enabled.', 'moksa-for-woocommerce' ),
				];
			}
			$before = self::shipment_ref( $order );
			call_user_func( [ $class, 'moksafowo_payuni_create_shipping' ], $order );
			$after = self::shipment_ref( wc_get_order( $order->get_id() ) );
			if ( '' !== $after && $after !== $before ) {
				return [
					'ok'      => true,
					'message' => 'OK',
				];
			}
			return [
				'ok'      => false,
				'message' => __( 'PAYUNi did not return a tracking number. This is usually a settings or order data problem — see the order notes for details.', 'moksa-for-woocommerce' ),
			];
		}

		$class = self::providers()[ $provider ][0] ?? null;
		if ( null === $class ) {
			return [
				'ok'      => false,
				'message' => __( 'Unsupported carrier.', 'moksa-for-woocommerce' ),
			];
		}
		return (array) call_user_func( [ $class, 'run' ], $order );
	}
}
