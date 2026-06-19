<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

use MoksaWeb\Mowc\Modules\EcpayShipping\Operations\CreateOrder as EcpayCreate;
use MoksaWeb\Mowc\Modules\NewebpayShipping\Operations\CreateShipment as NewebpayCreate;
use MoksaWeb\Mowc\Modules\PayuniShipping\Operations\CreateOrderUnified as PayuniCreate;
use MoksaWeb\Mowc\Modules\SmilepayShipping\Operations\CreateOrder as SmilepayCreate;

defined( 'ABSPATH' ) || exit;

/**
 * 破壞性操作:建立託運單(向物流商建單取號)。依訂單運送方式(method id 前綴
 * moksafowo_<provider>_shipping_)路由到對應 provider 的 Operations\Create*::run()。
 * 建單會向物流商真實下單取號(不可逆),故走人工確認關卡。各 Create 類自帶冪等保護。
 */
final class ShipmentOps {

	const CAP = 'manage_woocommerce';

	/**
	 * provider => [ CreateClass, 顯示名 ]。
	 *
	 * @return array<string, array{0:class-string, 1:string}>
	 */
	private static function providers(): array {
		return array(
			'ecpay'    => array( EcpayCreate::class, __( '綠界', 'mo-ectools' ) ),
			'payuni'   => array( PayuniCreate::class, __( 'PAYUNi', 'mo-ectools' ) ),
			'newebpay' => array( NewebpayCreate::class, __( '藍新', 'mo-ectools' ) ),
			'smilepay' => array( SmilepayCreate::class, __( '速買配', 'mo-ectools' ) ),
		);
	}

	private static function order_from( $args ): ?\WC_Order {
		$ref   = is_array( $args ) && isset( $args['order'] ) ? (string) $args['order'] : '';
		$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
		$order = $id ? wc_get_order( $id ) : false;
		return ( $order && 'shop_order' === $order->get_type() ) ? $order : null;
	}

	private static function resolve_provider( \WC_Order $order ): string {
		foreach ( $order->get_shipping_methods() as $m ) {
			if ( preg_match( '/^moksafowo_(ecpay|payuni|newebpay|smilepay)_shipping_/', (string) $m->get_method_id(), $mm ) ) {
				return $mm[1];
			}
		}
		return '';
	}

	/**
	 * 實際建單。PayUni 走 ShippingRequest 完整入口(同時支援 unified 多溫層 + 個別溫層),
	 * 它回 void,故以「建單後是否新出現物流單號」判定成敗;其餘 provider 走各自 Create*::run。
	 *
	 * @param string    $provider provider slug。
	 * @param \WC_Order $order    訂單。
	 * @return array{ok:bool, message?:string}
	 */
	private static function create_for( string $provider, \WC_Order $order ): array {
		if ( 'payuni' === $provider ) {
			$class = 'MoksaWeb\\Mowc\\Modules\\PayuniShipping\\Api\\ShippingRequest';
			if ( ! class_exists( $class ) ) {
				return array(
					'ok'      => false,
					'message' => __( 'PAYUNi 物流模組未啟用。', 'mo-ectools' ),
				);
			}
			$before = SearchableKeys::field_value( $order, 'shipping' );
			call_user_func( array( $class, 'moksafowo_payuni_create_shipping' ), $order );
			$after = SearchableKeys::field_value( wc_get_order( $order->get_id() ), 'shipping' );
			if ( '' !== $after && $after !== $before ) {
				return array(
					'ok'      => true,
					'message' => 'OK',
				);
			}
			return array(
				'ok'      => false,
				'message' => __( 'PAYUNi 建單未取得物流單號(可能 provider 設定或訂單資料問題,詳見訂單備註)。', 'mo-ectools' ),
			);
		}

		$map = self::providers();
		if ( ! isset( $map[ $provider ] ) ) {
			return array(
				'ok'      => false,
				'message' => __( '不支援的物流商。', 'mo-ectools' ),
			);
		}
		return (array) call_user_func( array( $map[ $provider ][0], 'run' ), $order );
	}

	/**
	 * @param mixed $args { order: string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$order = self::order_from( $args );
		if ( ! $order ) {
			return new \WP_Error( 'mo_ai_no_order', __( '找不到訂單。', 'mo-ectools' ) );
		}
		$provider = self::resolve_provider( $order );
		$map      = self::providers();
		if ( '' === $provider || ! isset( $map[ $provider ] ) ) {
			return new \WP_Error( 'mo_ai_no_shipping', __( '此訂單的運送方式不支援自動建立託運單。', 'mo-ectools' ) );
		}

		$existing = SearchableKeys::field_value( $order, 'shipping' );
		$summary  = sprintf(
			/* translators: 1: order number, 2: provider name */
			__( '為訂單 #%1$s 建立託運單(物流商:%2$s)。', 'mo-ectools' ),
			$order->get_order_number(),
			$map[ $provider ][1]
		);
		if ( '' !== $existing ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: existing shipping number */
				__( '(注意:此訂單已有物流單號 %s,可能會新增另一筆。)', 'mo-ectools' ),
				$existing
			);
		}

		return array(
			'order_id' => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'provider' => $provider,
			'summary'  => $summary,
		);
	}

	/**
	 * @param array<string,mixed> $params prepare() 的回傳。
	 * @return string|\WP_Error
	 */
	public static function apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$order    = wc_get_order( (int) ( $params['order_id'] ?? 0 ) );
		$provider = (string) ( $params['provider'] ?? '' );
		$map      = self::providers();
		if ( ! $order || ! isset( $map[ $provider ] ) ) {
			return new \WP_Error( 'mo_ai_no_order', __( '找不到訂單或物流商。', 'mo-ectools' ) );
		}

		$result = self::create_for( $provider, $order );
		if ( empty( $result['ok'] ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'mo_ai_create_failed', sprintf( __( '建立託運單失敗:%s', 'mo-ectools' ), (string) ( $result['message'] ?? '' ) ) );
		}

		$ship_no = SearchableKeys::field_value( wc_get_order( $order->get_id() ), 'shipping' );
		$suffix  = '';
		if ( '' !== $ship_no ) {
			/* translators: %s: shipping number */
			$suffix = sprintf( __( ':物流單號 %s', 'mo-ectools' ), $ship_no );
		}
		return sprintf(
			/* translators: 1: order number, 2: shipping number suffix(可能為空) */
			__( '✅ 已為訂單 #%1$s 建立託運單%2$s。', 'mo-ectools' ),
			$order->get_order_number(),
			$suffix
		);
	}

	const MAX_BATCH = 30;

	/**
	 * @param mixed $raw array 或逗號 / 空白分隔字串。
	 * @return string[]
	 */
	private static function normalize_refs( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,，、]+/u', $raw ) ?: array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$out[ $item ] = $item;
			}
		}
		return array_slice( array_values( $out ), 0, self::MAX_BATCH );
	}

	/**
	 * @param mixed $args { orders: array|string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function batch_prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$refs = self::normalize_refs( is_array( $args ) ? ( $args['orders'] ?? '' ) : '' );
		if ( empty( $refs ) ) {
			return new \WP_Error( 'mo_ai_no_orders', __( '沒有指定要建立託運單的訂單。', 'mo-ectools' ) );
		}
		$map         = self::providers();
		$found       = array();
		$unsupported = array();
		foreach ( $refs as $ref ) {
			$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
			$order = $id ? wc_get_order( $id ) : false;
			if ( ! $order || 'shop_order' !== $order->get_type() ) {
				$unsupported[] = $ref;
				continue;
			}
			$provider = self::resolve_provider( $order );
			if ( '' === $provider || ! isset( $map[ $provider ] ) ) {
				$unsupported[] = '#' . $order->get_order_number();
				continue;
			}
			$found[ $order->get_id() ] = array(
				'order_id' => $order->get_id(),
				'number'   => (string) $order->get_order_number(),
				'provider' => $provider,
			);
		}
		if ( empty( $found ) ) {
			return new \WP_Error( 'mo_ai_no_orders', __( '沒有可建立託運單的訂單(運送方式需為支援的物流商)。', 'mo-ectools' ) );
		}

		$orders  = array_values( $found );
		$numbers = implode( ' ', array_map( static fn( $o ) => '#' . $o['number'], $orders ) );
		$summary = sprintf(
			/* translators: 1: count, 2: order numbers */
			__( '為 %1$d 筆訂單建立託運單(%2$s)。', 'mo-ectools' ),
			count( $orders ),
			$numbers
		);
		if ( ! empty( $unsupported ) ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: skipped refs */
				__( '(略過無法建單:%s)', 'mo-ectools' ),
				implode( '、', $unsupported )
			);
		}

		return array(
			'orders'  => $orders,
			'summary' => $summary,
		);
	}

	/**
	 * @param array<string,mixed> $params batch_prepare() 的回傳。
	 * @return string|\WP_Error
	 */
	public static function batch_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$orders = is_array( $params['orders'] ?? null ) ? $params['orders'] : array();
		$map    = self::providers();
		$done   = array();
		$failed = array();
		foreach ( $orders as $o ) {
			$order    = wc_get_order( (int) ( $o['order_id'] ?? 0 ) );
			$provider = (string) ( $o['provider'] ?? '' );
			if ( ! $order || ! isset( $map[ $provider ] ) ) {
				$failed[] = '#' . ( $o['number'] ?? '?' );
				continue;
			}
			$result = self::create_for( $provider, $order );
			if ( ! empty( $result['ok'] ) ) {
				$ship_no = SearchableKeys::field_value( wc_get_order( $order->get_id() ), 'shipping' );
				$done[]  = '#' . $order->get_order_number() . ( '' !== $ship_no ? ':' . $ship_no : '' );
			} else {
				$failed[] = '#' . $order->get_order_number();
			}
		}

		$msg = sprintf(
			/* translators: 1: success, 2: total, 3: order:tracking list */
			__( '✅ 已為 %1$d/%2$d 筆建立託運單:%3$s。', 'mo-ectools' ),
			count( $done ),
			count( $done ) + count( $failed ),
			implode( ' ', $done )
		);
		if ( ! empty( $failed ) ) {
			$msg .= ' ' . sprintf(
				/* translators: %s: failed order numbers */
				__( '⚠️ 失敗:%s。', 'mo-ectools' ),
				implode( ' ', $failed )
			);
		}
		return $msg;
	}
}
